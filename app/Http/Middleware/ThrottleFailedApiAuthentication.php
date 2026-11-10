<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Slows token guessing. Only rejected requests count, so valid clients sharing an
 * address are never limited here.
 */
class ThrottleFailedApiAuthentication
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = 'api-auth-failures:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, (int) config('api.rate_limits.failed_auth'))) {
            $retryAfter = RateLimiter::availableIn($key);

            throw new ApiException(429, 'rate_limited', 'Too many failed authentication attempts.', ['retry_after' => $retryAfter], ['Retry-After' => (string) $retryAfter]);
        }

        $response = $next($request);

        if ($response->getStatusCode() === 401) {
            RateLimiter::hit($key, 60);
        }

        return $response;
    }
}
