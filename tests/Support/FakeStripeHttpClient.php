<?php

namespace Tests\Support;

use RuntimeException;
use Stripe\HttpClient\ClientInterface;

/**
 * Stands in for Stripe's network layer. Anything not explicitly set up fails the test,
 * so no test can reach Stripe.
 */
class FakeStripeHttpClient implements ClientInterface
{
    /** @var array<string, array<string, mixed>> response body by "METHOD /path" */
    public array $responses = [];

    /** @var list<array{method: string, path: string, params: array<string, mixed>}> */
    public array $requests = [];

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $path = (string) parse_url($absUrl, PHP_URL_PATH);
        $key = strtoupper($method).' '.$path;

        $this->requests[] = ['method' => strtoupper($method), 'path' => $path, 'params' => $params];

        if (! isset($this->responses[$key])) {
            throw new RuntimeException("Unexpected Stripe request: {$key}");
        }

        return [json_encode($this->responses[$key], JSON_THROW_ON_ERROR), 200, []];
    }

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        return array_map(fn (array $request) => $request['method'].' '.$request['path'], $this->requests);
    }
}
