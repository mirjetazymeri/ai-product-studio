<?php

namespace App\Services\AI;

use App\Enums\AiError;
use App\Services\AI\Exceptions\AiGenerationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * The only class that talks to the Gemini REST API.
 */
class GeminiService
{
    public const PROVIDER = 'gemini';

    private const RETRYABLE_STATUSES = [500, 502, 503, 504];

    private const CONTENT_FILTER_REASONS = ['SAFETY', 'RECITATION', 'LANGUAGE', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII'];

    /**
     * @throws AiGenerationException when the key or model is not configured
     */
    public function ensureConfigured(): void
    {
        $this->apiKey();
        $this->model();
    }

    public function model(): string
    {
        $model = config('services.gemini.model');

        if (blank($model)) {
            throw new AiGenerationException(AiError::MissingModel);
        }

        return $model;
    }

    /**
     * Longest a single generation can take, used to size the duplicate-request lock.
     */
    public function maximumDurationInSeconds(): int
    {
        $attempts = max(0, (int) config('services.gemini.max_retries')) + 1;
        $perAttempt = (int) config('services.gemini.connect_timeout') + (int) config('services.gemini.timeout');

        return ($perAttempt + 3) * $attempts;
    }

    /**
     * Send a prompt and return the JSON object Gemini produced for the schema.
     *
     * @param  array<string, mixed>  $schema
     *
     * @throws AiGenerationException
     */
    public function generateJson(string $systemInstruction, string $prompt, array $schema): GeminiResult
    {
        $this->ensureConfigured();

        $response = $this->sendWithRetries([
            'systemInstruction' => ['parts' => [['text' => $systemInstruction]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseJsonSchema' => $schema,
                'maxOutputTokens' => (int) config('services.gemini.max_output_tokens'),
            ],
        ]);

        return $this->parse($response);
    }

    /**
     * Look up the configured model without generating anything.
     *
     * @return array<string, mixed>
     *
     * @throws AiGenerationException
     */
    public function fetchModelDetails(): array
    {
        $this->ensureConfigured();

        try {
            $response = $this->request()->get($this->modelPath());
        } catch (ConnectionException $exception) {
            throw new AiGenerationException($this->connectionError($exception));
        }

        if (! $response->successful()) {
            throw $this->exceptionFor($response);
        }

        return $response->json() ?? [];
    }

    private function apiKey(): string
    {
        $key = config('services.gemini.api_key');

        if (blank($key)) {
            throw new AiGenerationException(AiError::MissingApiKey);
        }

        return $key;
    }

    private function modelPath(): string
    {
        return 'models/'.rawurlencode($this->model());
    }

    /**
     * The key travels in a header so it can never appear in a URL or a connection error.
     */
    private function request(): PendingRequest
    {
        return Http::baseUrl(config('services.gemini.base_url'))
            ->withHeaders(['x-goog-api-key' => $this->apiKey()])
            ->acceptJson()
            ->connectTimeout((int) config('services.gemini.connect_timeout'))
            ->timeout((int) config('services.gemini.timeout'));
    }

    /**
     * Retries only brief server-side faults and failed connections. Timeouts, quota
     * and authentication errors are final, because repeating them can't help.
     *
     * @param  array<string, mixed>  $payload
     */
    private function sendWithRetries(array $payload): Response
    {
        $maxRetries = max(0, (int) config('services.gemini.max_retries'));

        for ($attempt = 0; ; $attempt++) {
            try {
                $response = $this->request()->post($this->modelPath().':generateContent', $payload);
            } catch (ConnectionException $exception) {
                $error = $this->connectionError($exception);

                if ($error === AiError::Timeout || $attempt >= $maxRetries) {
                    Log::warning('Gemini connection failed.', ['error' => $error->value, 'model' => $this->model()]);

                    throw new AiGenerationException($error);
                }

                $this->backOff($attempt);

                continue;
            }

            if ($response->successful()) {
                return $response;
            }

            if (! in_array($response->status(), self::RETRYABLE_STATUSES, true) || $attempt >= $maxRetries) {
                throw $this->exceptionFor($response);
            }

            $this->backOff($attempt);
        }
    }

    private function backOff(int $attempt): void
    {
        Sleep::for((2 ** $attempt) * 500 + random_int(0, 250))->milliseconds();
    }

    private function connectionError(ConnectionException $exception): AiError
    {
        return Str::contains($exception->getMessage(), ['cURL error 28', 'timed out'], ignoreCase: true)
            ? AiError::Timeout
            : AiError::NetworkError;
    }

    private function exceptionFor(Response $response): AiGenerationException
    {
        $status = $response->status();
        $reason = collect($response->json('error.details') ?? [])->pluck('reason')->filter()->first();

        $error = match (true) {
            $status === 400 && $reason === 'API_KEY_INVALID', $status === 401, $status === 403 => AiError::InvalidApiKey,
            $status === 404 => AiError::ModelUnavailable,
            $status === 429 => AiError::QuotaExceeded,
            $status >= 500 => AiError::ProviderUnavailable,
            default => AiError::ProviderError,
        };

        Log::warning('Gemini request failed.', [
            'http_status' => $status,
            'api_status' => $response->json('error.status'),
            'reason' => $reason,
            'detail' => $this->redact(Str::limit((string) $response->json('error.message'), 300)),
            'model' => $this->model(),
        ]);

        return new AiGenerationException($error, retryAfter: $this->retryAfter($response));
    }

    private function retryAfter(Response $response): ?int
    {
        $delay = collect($response->json('error.details') ?? [])->pluck('retryDelay')->filter()->first();

        if (is_string($delay) && preg_match('/^(\d+)/', $delay, $matches) === 1) {
            return (int) $matches[1];
        }

        $header = $response->header('Retry-After');

        return is_numeric($header) ? (int) $header : null;
    }

    private function redact(string $text): string
    {
        return str_replace($this->apiKey(), '[redacted]', $text);
    }

    private function parse(Response $response): GeminiResult
    {
        $body = $response->json();

        if (! is_array($body)) {
            throw new AiGenerationException(AiError::InvalidResponse);
        }

        $usage = TokenUsage::fromGeminiMetadata($body['usageMetadata'] ?? null);

        if (filled(data_get($body, 'promptFeedback.blockReason'))) {
            throw new AiGenerationException(AiError::BlockedResponse, $usage);
        }

        $candidate = data_get($body, 'candidates.0');

        if (! is_array($candidate)) {
            throw new AiGenerationException(AiError::EmptyResponse, $usage);
        }

        $this->ensureFinishedCleanly($candidate['finishReason'] ?? null, $usage);

        $text = collect(data_get($candidate, 'content.parts', []))
            ->reject(fn ($part) => ! is_array($part) || ($part['thought'] ?? false))
            ->pluck('text')
            ->filter(fn ($value) => is_string($value))
            ->implode('');

        if (blank($text)) {
            throw new AiGenerationException(AiError::EmptyResponse, $usage);
        }

        $data = json_decode($text, true);

        if (! is_array($data) || array_is_list($data)) {
            throw new AiGenerationException(AiError::InvalidResponse, $usage);
        }

        return new GeminiResult($data, $usage);
    }

    private function ensureFinishedCleanly(mixed $reason, TokenUsage $usage): void
    {
        if ($reason === null || $reason === 'STOP') {
            return;
        }

        Log::warning('Gemini stopped early.', ['finish_reason' => $reason, 'model' => $this->model()]);

        throw new AiGenerationException(
            in_array($reason, self::CONTENT_FILTER_REASONS, true) ? AiError::BlockedResponse : AiError::InvalidResponse,
            $usage,
        );
    }
}
