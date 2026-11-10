<?php

namespace App\Services\Billing;

/**
 * Chooses the provider from server configuration only. Nothing in a request can affect it.
 */
class BillingManager
{
    public function __construct(
        private readonly DemoBillingProvider $demo,
        private readonly StripeBillingProvider $stripe,
        private readonly DisabledBillingProvider $disabled,
    ) {}

    public function provider(): BillingProvider
    {
        return match (config('billing.provider')) {
            'demo' => $this->demoEnabled() ? $this->demo : $this->disabled,
            'stripe' => $this->stripe,
            default => $this->disabled,
        };
    }

    public function demoEnabled(): bool
    {
        return config('billing.provider') === 'demo'
            && (! app()->isProduction() || config('billing.allow_demo_in_production'));
    }

    public function demo(): DemoBillingProvider
    {
        return $this->demo;
    }
}
