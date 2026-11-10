<?php

namespace App\Services\Billing;

use App\Enums\SubscriptionStatus;
use Illuminate\Support\Carbon;

/**
 * What a provider has verified about one user's subscription.
 */
final readonly class SubscriptionState
{
    /**
     * @param  SubscriptionPlan  $plan  what the user may use now
     * @param  SubscriptionPlan|null  $subscribedPlan  what they signed up for, even if lapsed
     */
    public function __construct(
        public SubscriptionPlan $plan,
        public ?SubscriptionPlan $subscribedPlan,
        public SubscriptionStatus $status,
        public ?Carbon $renewsAt = null,
        public ?Carbon $endsAt = null,
        public ?Carbon $graceEndsAt = null,
        public ?string $reference = null,
    ) {}

    public function canCancel(): bool
    {
        return in_array($this->status, [SubscriptionStatus::Active, SubscriptionStatus::Trialing, SubscriptionStatus::PastDue], true);
    }

    public function canResume(): bool
    {
        return $this->status === SubscriptionStatus::Canceling;
    }
}
