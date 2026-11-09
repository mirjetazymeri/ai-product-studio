<?php

namespace App\Services\AI\Exceptions;

use App\Enums\AiError;
use App\Services\AI\TokenUsage;
use RuntimeException;

final class AiGenerationException extends RuntimeException
{
    public function __construct(
        public readonly AiError $error,
        public readonly ?TokenUsage $usage = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($error->value);
    }
}
