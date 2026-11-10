<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\ApiIdempotencyKey;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes a retried POST safe. Only successful responses are remembered, so a request
 * that failed validation or hit a limit can be corrected and sent again.
 */
class EnforceIdempotency
{
    private const KEY_FORMAT = '/^[A-Za-z0-9_\-:.]{1,255}$/';

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if ($key === null) {
            return $next($request);
        }

        if (preg_match(self::KEY_FORMAT, $key) !== 1) {
            throw new ApiException(400, 'invalid_idempotency_key', 'Idempotency-Key must be 1 to 255 letters, numbers, or the characters - _ : .');
        }

        $attributes = [
            'user_id' => $request->user()->id,
            'operation' => $request->method().' '.$request->path(),
            'key_hash' => hash('sha256', $key),
        ];
        $fingerprint = $this->fingerprint($request);

        $record = $this->claim($attributes, $fingerprint);

        if ($record->status === ApiIdempotencyKey::COMPLETED) {
            return $this->replay($record);
        }

        $response = $next($request);

        if ($response->isSuccessful()) {
            $record->update([
                'status' => ApiIdempotencyKey::COMPLETED,
                'response_status' => $response->getStatusCode(),
                'response' => ['body' => $response->getContent(), 'location' => $response->headers->get('Location')],
                'locked_at' => null,
            ]);
        } else {
            $record->delete();
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function claim(array $attributes, string $fingerprint): ApiIdempotencyKey
    {
        $existing = ApiIdempotencyKey::query()->where($attributes)->first();

        if ($existing !== null && $this->isDiscardable($existing)) {
            $existing->delete();
            $existing = null;
        }

        if ($existing === null) {
            try {
                return ApiIdempotencyKey::query()->create([
                    ...$attributes,
                    'request_hash' => $fingerprint,
                    'status' => ApiIdempotencyKey::IN_PROGRESS,
                    'locked_at' => now(),
                    'expires_at' => now()->addHours((int) config('api.idempotency.retention_hours')),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Another attempt with this key arrived at the same moment.
                $existing = ApiIdempotencyKey::query()->where($attributes)->firstOrFail();
            }
        }

        if (! hash_equals($existing->request_hash, $fingerprint)) {
            throw new ApiException(409, 'idempotency_key_conflict', 'This Idempotency-Key was already used with a different request.');
        }

        if ($existing->status === ApiIdempotencyKey::IN_PROGRESS) {
            throw new ApiException(409, 'idempotency_in_progress', 'A request with this Idempotency-Key is still being processed.', ['retry_after' => 1], ['Retry-After' => '1']);
        }

        return $existing;
    }

    private function isDiscardable(ApiIdempotencyKey $record): bool
    {
        if ($record->expires_at->isPast()) {
            return true;
        }

        return $record->status === ApiIdempotencyKey::IN_PROGRESS
            && $record->locked_at->lt(now()->subSeconds((int) config('api.idempotency.in_progress_seconds')));
    }

    private function replay(ApiIdempotencyKey $record): Response
    {
        $headers = ['Content-Type' => 'application/json', 'Idempotency-Replayed' => 'true'];

        if ($record->response['location'] !== null) {
            $headers['Location'] = $record->response['location'];
        }

        return response($record->response['body'], $record->response_status, $headers);
    }

    /**
     * The same fields in a different order are still the same request.
     */
    private function fingerprint(Request $request): string
    {
        $input = $request->all();

        $this->sortRecursively($input);

        return hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<mixed>  $input
     */
    private function sortRecursively(array &$input): void
    {
        ksort($input);

        foreach ($input as &$value) {
            if (is_array($value)) {
                $this->sortRecursively($value);
            }
        }
    }
}
