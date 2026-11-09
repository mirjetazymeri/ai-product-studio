<?php

namespace Tests\Concerns;

trait FakesGemini
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function generatedFields(array $overrides = []): array
    {
        return array_replace([
            'title' => 'Leather Tote Bag',
            'short_description' => 'A hand-stitched leather tote.',
            'long_description' => 'Made from full-grain leather with brass hardware.',
            'meta_title' => 'Leather Tote Bag',
            'meta_description' => 'A hand-stitched leather tote with brass hardware.',
            'slug' => 'leather-tote-bag',
            'keywords' => ['leather tote', 'tote bag'],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>|string  $output  decoded fields, or raw model text
     * @return array<string, mixed>
     */
    protected function geminiBody(array|string $output = [], string $finishReason = 'STOP'): array
    {
        $text = is_string($output) ? $output : json_encode($this->generatedFields($output));

        return [
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [['text' => $text]]],
                'finishReason' => $finishReason,
            ]],
            'usageMetadata' => [
                'promptTokenCount' => 120,
                'candidatesTokenCount' => 80,
                'thoughtsTokenCount' => 20,
                'totalTokenCount' => 220,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function geminiError(int $code, string $status, string $message = 'Request failed.', array $details = []): array
    {
        return ['error' => ['code' => $code, 'status' => $status, 'message' => $message, 'details' => $details]];
    }
}
