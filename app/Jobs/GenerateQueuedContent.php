<?php

namespace App\Jobs;

use App\Enums\AiError;
use App\Services\AI\ProductContentGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs one generation accepted through the API, waiting out any rate limit.
 * The custom instructions travel here because generation history doesn't keep them.
 */
class GenerateQueuedContent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public int $generationId, public ?string $customInstructions = null) {}

    public function handle(ProductContentGenerator $generator): void
    {
        $delay = $generator->processQueued($this->generationId, $this->customInstructions);

        if ($delay === null) {
            return;
        }

        // The sync driver ignores delays, so this attempt can only end here.
        if (config('queue.default') === 'sync') {
            $generator->failActive($this->generationId, AiError::RateLimited);

            return;
        }

        self::dispatch($this->generationId, $this->customInstructions)->delay($delay);
    }

    public function failed(Throwable $exception): void
    {
        app(ProductContentGenerator::class)->failActive($this->generationId, AiError::Interrupted);
    }
}
