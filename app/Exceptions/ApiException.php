<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A failure that is safe to describe to an API client exactly as written.
 */
final class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $extra  additional top-level response fields
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $extra = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }
}
