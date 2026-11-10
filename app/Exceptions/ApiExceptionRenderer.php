<?php

namespace App\Exceptions;

use App\Enums\AiError;
use App\Services\AI\Exceptions\AiGenerationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Every API failure leaves here as the same JSON shape. Exception text is only used
 * from classes written for clients, so SQL, paths and class names can't leak.
 */
final class ApiExceptionRenderer
{
    public function render(Throwable $exception): Response
    {
        return match (true) {
            // Already a finished response, such as a rate limiter's own 429.
            $exception instanceof HttpResponseException => $exception->getResponse(),
            $exception instanceof ValidationException => response()->json(['message' => 'The given data was invalid.', 'errors' => $exception->errors()], $exception->status),
            $exception instanceof ApiException => $this->error($exception->status, $exception->errorCode, $exception->getMessage(), $exception->extra, $exception->headers),
            $exception instanceof AiGenerationException => $this->aiError($exception),
            $exception instanceof AuthenticationException => $this->error(401, 'unauthenticated', 'A valid API token is required.', headers: ['WWW-Authenticate' => 'Bearer']),
            $exception instanceof AccessDeniedHttpException => $this->forbidden($exception),
            $exception instanceof NotFoundHttpException => $this->error(404, 'not_found', 'The requested resource was not found.'),
            $exception instanceof MethodNotAllowedHttpException => $this->error(405, 'method_not_allowed', 'This method is not supported for this resource.', headers: $exception->getHeaders()),
            $exception instanceof ThrottleRequestsException => $this->throttled($exception),
            $exception instanceof UniqueConstraintViolationException => $this->error(409, 'conflict', 'This conflicts with an existing record.'),
            $exception instanceof LockTimeoutException => $this->error(409, 'duplicate_request', 'Another request is in progress. Please try again shortly.', headers: ['Retry-After' => '5']),
            $exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 503 => $this->error(503, 'service_unavailable', 'The service is temporarily unavailable.', headers: $exception->getHeaders()),
            $exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500 => $this->error($exception->getStatusCode(), 'request_error', 'The request could not be processed.', headers: $exception->getHeaders()),
            default => $this->error(500, 'server_error', 'Something went wrong on our side.'),
        };
    }

    private function aiError(AiGenerationException $exception): JsonResponse
    {
        $retryAfter = $exception->retryAfter;

        // The daily allowance resets at midnight, so this is one retry time we do know.
        if ($exception->error === AiError::DailyLimitReached) {
            $retryAfter = (int) ceil(now()->diffInSeconds(today()->addDay()));
        }

        if ($exception->error === AiError::MonthlyLimitReached) {
            $retryAfter = (int) ceil(now()->diffInSeconds(now('UTC')->startOfMonth()->addMonth()));
        }

        return $this->error(
            $exception->error->httpStatus(),
            $exception->error->publicCode(),
            $exception->error->publicMessage(),
            ['retry_after' => $retryAfter],
            $retryAfter === null ? [] : ['Retry-After' => (string) $retryAfter],
        );
    }

    private function forbidden(AccessDeniedHttpException $exception): JsonResponse
    {
        $missing = $exception->getPrevious();

        return $missing instanceof MissingAbilityException
            ? $this->error(403, 'insufficient_ability', 'This token doesn\'t have the permission this request needs.', ['required_abilities' => array_values($missing->abilities())])
            : $this->error(403, 'forbidden', 'You don\'t have access to this resource.');
    }

    private function throttled(ThrottleRequestsException $exception): JsonResponse
    {
        $headers = $exception->getHeaders();
        $retryAfter = isset($headers['Retry-After']) ? (int) $headers['Retry-After'] : null;

        return $this->error(429, 'rate_limited', 'Too many requests. Please slow down.', ['retry_after' => $retryAfter], $headers);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, mixed>  $headers
     */
    private function error(int $status, string $code, string $message, array $extra = [], array $headers = []): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code, ...$extra], $status, $headers);
    }
}
