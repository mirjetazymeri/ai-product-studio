<?php

namespace App\Jobs;

use App\Models\WooCommerceSyncRun;
use App\Services\WooCommerce\WooCommerceSyncRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Handles one item, then queues itself for the next, waiting out any rate limit.
 */
class ProcessWooCommerceSyncRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $runId) {}

    public function handle(WooCommerceSyncRunner $runner): void
    {
        $run = WooCommerceSyncRun::query()->find($this->runId);

        if ($run === null || $run->isFinished() || $run->status === WooCommerceSyncRun::READY) {
            return;
        }

        $delay = $runner->next($run);

        // The sync driver ignores delays, so waiting out a limit there would loop forever.
        if ($delay === null || ($delay > 0 && config('queue.default') === 'sync')) {
            return;
        }

        self::dispatch($this->runId)->delay($delay);
    }
}
