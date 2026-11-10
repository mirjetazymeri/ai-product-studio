<?php

namespace App\Services\Billing;

use App\Models\User;
use App\Services\Billing\Exceptions\PlanRestrictionException;

/**
 * The one place that answers "may this user do this?". It works from the provider's
 * verified state, so controllers, API endpoints and queue jobs all get the same answer.
 */
class PlanEntitlementService
{
    public function __construct(
        private readonly BillingManager $billing,
        private readonly SubscriptionPlanService $plans,
    ) {}

    public function state(User $user): SubscriptionState
    {
        return $this->billing->provider()->state($user);
    }

    public function plan(User $user): SubscriptionPlan
    {
        return $this->state($user)->plan;
    }

    public function limit(User $user, string $key): int
    {
        return $this->plan($user)->limit($key);
    }

    public function can(User $user, string $feature): bool
    {
        return $this->isAvailable($feature) && in_array($feature, $this->plan($user)->features, true);
    }

    public function denialReason(User $user, string $feature): ?string
    {
        if ($this->can($user, $feature)) {
            return null;
        }

        $label = $this->definition($feature)['label'] ?? 'This feature';

        if (! $this->isAvailable($feature)) {
            return "{$label} is coming soon.";
        }

        return "{$label} isn't included in your {$this->plan($user)->name} plan. Upgrade to use it. Your existing data is safe.";
    }

    /**
     * @throws PlanRestrictionException
     */
    public function ensure(User $user, string $feature): void
    {
        if (($reason = $this->denialReason($user, $feature)) !== null) {
            throw new PlanRestrictionException($reason, 'plan_feature_unavailable', $feature, $this->plan($user)->key);
        }
    }

    /**
     * Run inside a transaction, so concurrent creates are counted one at a time. The count
     * is a locking read because a plain one could use a snapshot from before the lock wait.
     *
     * @throws PlanRestrictionException
     */
    public function ensureCanCreateProduct(User $user, bool $lock = true): void
    {
        if ($lock) {
            User::query()->whereKey($user->id)->lockForUpdate()->first();
        }

        $this->ensure($user, 'products.create');

        $plan = $this->plan($user);
        $products = $user->products()->when($lock, fn ($query) => $query->sharedLock())->count();

        if ($products >= $plan->limit('products')) {
            throw new PlanRestrictionException(
                "You've reached the {$plan->limit('products')} products included in your {$plan->name} plan. Your existing products stay available. Upgrade to add more.",
                'plan_limit_reached',
                'products.create',
                $plan->key,
            );
        }
    }

    /**
     * @return list<array{label: string, available: bool}>
     */
    public function featureList(SubscriptionPlan $plan): array
    {
        return array_map(
            fn (string $feature) => ['label' => $this->definition($feature)['label'] ?? $feature, 'available' => $this->isAvailable($feature)],
            $plan->features,
        );
    }

    /**
     * Read from the whole array, because feature keys contain dots themselves.
     *
     * @return array{label?: string, available?: bool}
     */
    private function definition(string $feature): array
    {
        return config('billing.features')[$feature] ?? [];
    }

    public function isAvailable(string $feature): bool
    {
        return (bool) ($this->definition($feature)['available'] ?? false);
    }
}
