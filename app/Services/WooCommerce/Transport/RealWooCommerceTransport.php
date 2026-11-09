<?php

namespace App\Services\WooCommerce\Transport;

use App\Enums\WooCommerceError;
use App\Models\WooCommerceStore;
use App\Services\WooCommerce\Exceptions\WooCommerceException;
use App\Services\WooCommerce\Http\StoreUrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * The only class that sends HTTP requests to a WooCommerce store.
 */
class RealWooCommerceTransport implements WooCommerceTransport
{
    private const API_PREFIX = 'wp-json/wc/v3/';

    private const RETRYABLE_STATUSES = [502, 503, 504];

    private const SSL_CURL_ERRORS = ['cURL error 35', 'cURL error 51', 'cURL error 58', 'cURL error 59', 'cURL error 60', 'cURL error 77', 'cURL error 83'];

    public function __construct(private readonly StoreUrlGuard $guard) {}

    public function listProducts(WooCommerceStore $store, int $page, int $perPage): array
    {
        $response = $this->send($store, 'GET', self::API_PREFIX.'products', [
            'page' => $page,
            'per_page' => min(100, max(1, $perPage)),
            'orderby' => 'id',
            'order' => 'asc',
        ]);

        $products = $response->json();

        if (! is_array($products) || ! array_is_list($products)) {
            throw new WooCommerceException(WooCommerceError::MissingApi);
        }

        return ['products' => $products, 'total_pages' => max(1, (int) $response->header('X-WP-TotalPages'))];
    }

    public function getProduct(WooCommerceStore $store, int $remoteId): array
    {
        return $this->productFrom($this->send($store, 'GET', self::API_PREFIX."products/{$remoteId}"));
    }

    /**
     * Writes are never retried here, because after a lost response nobody can tell
     * whether the store applied them.
     */
    public function updateProduct(WooCommerceStore $store, int $remoteId, array $fields): array
    {
        return $this->productFrom($this->send($store, 'PUT', self::API_PREFIX."products/{$remoteId}", json: $fields));
    }

    public function systemStatus(WooCommerceStore $store): array
    {
        return $this->send($store, 'GET', self::API_PREFIX.'system_status')->json() ?? [];
    }

    /**
     * The public site index, which lists the REST namespaces installed plugins provide.
     */
    public function siteIndex(WooCommerceStore $store): array
    {
        return $this->send($store, 'GET', 'wp-json/', authenticate: false)->json() ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function productFrom(Response $response): array
    {
        $product = $response->json();

        if (! is_array($product) || ! is_int($product['id'] ?? null)) {
            throw new WooCommerceException(WooCommerceError::InvalidResponse);
        }

        return $product;
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, string>|null  $json
     */
    private function send(WooCommerceStore $store, string $method, string $path, array $query = [], ?array $json = null, bool $authenticate = true): Response
    {
        $isRead = $method === 'GET';
        $retries = $isRead ? max(0, (int) config('woocommerce.read_retries')) : 0;

        for ($attempt = 0; ; $attempt++) {
            $this->throttle($store);

            try {
                $response = $this->request($store, $authenticate)->send($method, $store->url.'/'.$path, array_filter([
                    'query' => $query,
                    'json' => $json,
                ]));
            } catch (ConnectionException $exception) {
                $error = $this->connectionError($exception);

                if (! $isRead) {
                    $this->logFailure($store, $path, $error);

                    throw new WooCommerceException($this->isDefinitelyNotSent($error) ? $error : WooCommerceError::OutcomeUnknown);
                }

                if ($error === WooCommerceError::NetworkError && $attempt < $retries) {
                    $this->backOff($attempt);

                    continue;
                }

                $this->logFailure($store, $path, $error);

                throw new WooCommerceException($error);
            }

            if ($response->successful()) {
                return $response;
            }

            if ($isRead && in_array($response->status(), self::RETRYABLE_STATUSES, true) && $attempt < $retries) {
                $this->backOff($attempt);

                continue;
            }

            throw $this->exceptionFor($store, $path, $response);
        }
    }

    /**
     * Redirects are refused so credentials can't be forwarded to another host. The
     * address is resolved and validated again for each request, then pinned for it.
     */
    private function request(WooCommerceStore $store, bool $authenticate): PendingRequest
    {
        $host = (string) parse_url($store->url, PHP_URL_HOST);
        $address = $this->guard->safeAddressFor($host);
        $pinned = str_contains($address, ':') ? "[{$address}]" : $address;

        $request = Http::acceptJson()
            ->connectTimeout((int) config('woocommerce.connect_timeout'))
            ->timeout((int) config('woocommerce.timeout'))
            ->withUserAgent('AI-Product-Studio')
            ->withOptions([
                'allow_redirects' => false,
                'verify' => true,
                'curl' => [
                    CURLOPT_RESOLVE => ["{$host}:443:{$pinned}"],
                    CURLOPT_PROTOCOLS_STR => 'https',
                    CURLOPT_MAXFILESIZE_LARGE => (int) config('woocommerce.max_response_bytes'),
                ],
            ]);

        return $authenticate ? $request->withBasicAuth((string) $store->consumer_key, (string) $store->consumer_secret) : $request;
    }

    private function throttle(WooCommerceStore $store): void
    {
        $key = 'woocommerce:store:'.$store->id;

        if (RateLimiter::tooManyAttempts($key, (int) config('woocommerce.requests_per_minute'))) {
            throw new WooCommerceException(WooCommerceError::RateLimited, RateLimiter::availableIn($key) + 1);
        }

        RateLimiter::hit($key, 60);
    }

    private function backOff(int $attempt): void
    {
        Sleep::for((2 ** $attempt) * 500)->milliseconds();
    }

    private function connectionError(ConnectionException $exception): WooCommerceError
    {
        $message = $exception->getMessage();

        return match (true) {
            Str::contains($message, ['cURL error 28', 'timed out'], ignoreCase: true) => WooCommerceError::Timeout,
            Str::contains($message, self::SSL_CURL_ERRORS) => WooCommerceError::SslError,
            default => WooCommerceError::NetworkError,
        };
    }

    /**
     * A failed TLS handshake means the request body never left this server.
     */
    private function isDefinitelyNotSent(WooCommerceError $error): bool
    {
        return $error === WooCommerceError::SslError;
    }

    private function exceptionFor(WooCommerceStore $store, string $path, Response $response): WooCommerceException
    {
        $status = $response->status();
        $code = (string) $response->json('code');
        $error = WooCommerceError::fromHttpStatus($status, $code);

        $this->logFailure($store, $path, $error, $status, $code);

        $retryAfter = $response->header('Retry-After');

        return new WooCommerceException($error, is_numeric($retryAfter) ? (int) $retryAfter : null);
    }

    /**
     * Records only identifiers: never URLs with hosts, headers, bodies or exception text.
     */
    private function logFailure(WooCommerceStore $store, string $path, WooCommerceError $error, ?int $status = null, ?string $code = null): void
    {
        Log::warning('WooCommerce request failed.', [
            'store_id' => $store->id,
            'path' => (string) preg_replace('/\d+/', '{id}', $path),
            'error' => $error->value,
            'http_status' => $status,
            'code' => $code === null ? null : Str::limit($code, 80, ''),
        ]);
    }
}
