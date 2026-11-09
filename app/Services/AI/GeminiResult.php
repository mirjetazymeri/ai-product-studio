<?php

namespace App\Services\AI;

final readonly class GeminiResult
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public array $data,
        public TokenUsage $usage,
    ) {}
}
