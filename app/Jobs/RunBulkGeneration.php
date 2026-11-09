<?php

namespace App\Jobs;

use App\Services\AI\BulkGenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Handles one item, then queues itself for the next, waiting out any rate limit.
 */
class RunBulkGeneration implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public int $batchId) {}

    public function handle(BulkGenerationService $bulk): void
    {
        $delay = $bulk->processNext($this->batchId);

        if ($delay === null) {
            return;
        }

        // The sync driver ignores delays, so waiting out a limit there would loop forever.
        if ($delay > 0 && config('queue.default') === 'sync') {
            return;
        }

        self::dispatch($this->batchId)->delay($delay);
    }
}
