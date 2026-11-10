<?php

namespace App\Console\Commands;

use App\Services\Billing\BillingManager;
use App\Services\Billing\StripeBillingProvider;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Laravel\Cashier\Subscription;
use Throwable;

#[Signature('billing:reconcile')]
#[Description('Re-read open subscriptions from Stripe, in case a webhook was missed')]
class ReconcileStripeSubscriptions extends Command
{
    public function handle(BillingManager $billing): int
    {
        $provider = $billing->provider();

        if (! $provider instanceof StripeBillingProvider || $provider->unavailableReason() !== null) {
            $this->info('Stripe billing is not active, so there is nothing to reconcile.');

            return self::SUCCESS;
        }

        $failed = 0;

        Subscription::query()->whereNotIn('stripe_status', ['canceled', 'incomplete_expired'])->chunkById(100, function ($subscriptions) use (&$failed) {
            foreach ($subscriptions as $subscription) {
                try {
                    $subscription->syncStripeStatus();
                } catch (Throwable) {
                    $failed++;
                }
            }
        });

        $this->info("Reconciled. {$failed} subscriptions could not be read.");

        return self::SUCCESS;
    }
}
