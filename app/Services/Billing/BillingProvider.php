<?php

namespace App\Services\Billing;

use App\Enums\BillingMode;
use App\Models\User;
use App\Services\Billing\Exceptions\BillingException;

interface BillingProvider
{
    public function mode(): BillingMode;

    /**
     * Why billing actions can't run right now, or null when they can. Reading the
     * current subscription always works, so entitlements don't depend on this.
     */
    public function unavailableReason(): ?string;

    public function state(User $user): SubscriptionState;

    /**
     * @throws BillingException
     */
    public function changePlan(User $user, SubscriptionPlan $plan): BillingResult;

    /**
     * @throws BillingException
     */
    public function cancel(User $user): BillingResult;

    /**
     * @throws BillingException
     */
    public function resume(User $user): BillingResult;

    /**
     * @return list<BillingRecord>
     */
    public function records(User $user): array;

    /**
     * Only ever returns a record belonging to this user.
     */
    public function findRecord(User $user, string $id): ?BillingRecord;

    /**
     * @throws BillingException
     */
    public function portalUrl(User $user, string $returnUrl): ?string;
}
