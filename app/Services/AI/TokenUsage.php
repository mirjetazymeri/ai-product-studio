<?php

namespace App\Services\AI;

final readonly class TokenUsage
{
    public function __construct(
        public ?int $input = null,
        public ?int $output = null,
        public ?int $total = null,
    ) {}

    /**
     * Uses only figures Gemini reported. Thinking tokens are billed as output, so they count as output.
     */
    public static function fromGeminiMetadata(mixed $metadata): self
    {
        if (! is_array($metadata)) {
            return new self;
        }

        $number = fn (string $key): ?int => is_int($metadata[$key] ?? null) ? $metadata[$key] : null;
        $candidates = $number('candidatesTokenCount');

        return new self(
            input: $number('promptTokenCount'),
            output: $candidates === null ? null : $candidates + ($number('thoughtsTokenCount') ?? 0),
            total: $number('totalTokenCount'),
        );
    }

    /**
     * @return array{input_tokens: ?int, output_tokens: ?int, total_tokens: ?int}
     */
    public function toColumns(): array
    {
        return [
            'input_tokens' => $this->input,
            'output_tokens' => $this->output,
            'total_tokens' => $this->total,
        ];
    }
}
