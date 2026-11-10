<?php

namespace App\Services\Billing;

use App\Enums\BillingMode;
use App\Enums\SubscriptionStatus;
use App\Models\User;
use App\Services\Billing\Exceptions\BillingException;

/**
 * Used when billing isn't safely configured: everyone is on the default plan.
 */
class DisabledBillingProvider implements BillingProvider
{
    public function __construct(private readonly SubscriptionPlanService $plans) {}

    public function mode(): BillingMode
    {
        return BillingMode::Disabled;
    }

    public function unavailableReason(): string
    {
        return 'Plan changes aren\'t available on this installation.';
    }

    public function state(User $user): SubscriptionState
    {
        return new SubscriptionState($this->plans->default(), null, SubscriptionStatus::Free);
    }

    public function changePlan(User $user, SubscriptionPlan $plan): BillingResult
    {
        throw new BillingException($this->unavailableReason());
    }

    public function cancel(User $user): BillingResult
    {
        throw new BillingException($this->unavailableReason());
    }

    public function resume(User $user): BillingResult
    {
        throw new BillingException($this->unavailableReason());
    }

    public function records(User $user): array
    {
        return [];
    }

    public function findRecord(User $user, string $id): ?BillingRecord
    {
        return null;
    }

    public function portalUrl(User $user, string $returnUrl): ?string
    {
        return null;
    }
}
