<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs before authentication, so a token is never processed over plain HTTP.
 */
class PrepareApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('api.require_https') && ! $request->secure()) {
            throw new ApiException(403, 'https_required', 'This API is only available over HTTPS.');
        }

        $request->headers->set('Accept', 'application/json');

        $this->rejectMalformedJson($request);

        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-API-Version', 'v1');

        return $response;
    }

    /**
     * Without this, invalid JSON reads as an empty body and reports misleading field errors.
     */
    private function rejectMalformedJson(Request $request): void
    {
        $body = $request->getContent();

        if (! $request->isJson() || trim($body) === '') {
            return;
        }

        if (! is_array(json_decode($body, true))) {
            throw new ApiException(400, 'malformed_json', 'The request body is not valid JSON.');
        }
    }
}
