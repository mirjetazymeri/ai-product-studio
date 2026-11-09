<?php

namespace App\Console\Commands;

use App\Services\AI\Exceptions\AiGenerationException;
use App\Services\AI\GeminiService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ai:gemini-check')]
#[Description('Check that the configured Gemini model is available, without generating content')]
class CheckGeminiModel extends Command
{
    public function handle(GeminiService $gemini): int
    {
        try {
            $details = $gemini->fetchModelDetails();
        } catch (AiGenerationException $exception) {
            $this->error($exception->error->message());

            return self::FAILURE;
        }

        $methods = $details['supportedGenerationMethods'] ?? [];

        $this->info('Model available: '.($details['name'] ?? $gemini->model()));
        $this->line('Supports generateContent: '.(in_array('generateContent', $methods, true) ? 'yes' : 'no'));

        return self::SUCCESS;
    }
}
