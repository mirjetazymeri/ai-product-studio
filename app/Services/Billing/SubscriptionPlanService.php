<?php

namespace App\Services\Billing;

/**
 * The only reader of the plan definitions in config/billing.php.
 */
class SubscriptionPlanService
{
    /**
     * @return list<SubscriptionPlan>
     */
    public function all(): array
    {
        $plans = array_map($this->build(...), array_keys(config('billing.plans')));

        usort($plans, fn (SubscriptionPlan $a, SubscriptionPlan $b) => $a->rank <=> $b->rank);

        return $plans;
    }

    public function find(string $key): ?SubscriptionPlan
    {
        return is_array(config("billing.plans.{$key}")) ? $this->build($key) : null;
    }

    public function default(): SubscriptionPlan
    {
        return $this->find((string) config('billing.default_plan')) ?? $this->build('free');
    }

    /**
     * An unrecognised price grants nothing.
     */
    public function forStripePrice(?string $price): ?SubscriptionPlan
    {
        if (blank($price)) {
            return null;
        }

        foreach ($this->all() as $plan) {
            if ($plan->stripePrice === $price) {
                return $plan;
            }
        }

        return null;
    }

    private function build(string $key): SubscriptionPlan
    {
        $plan = config("billing.plans.{$key}");

        return new SubscriptionPlan(
            key: $key,
            name: $plan['name'],
            rank: (int) $plan['rank'],
            priceCents: (int) $plan['price_cents'],
            stripePrice: $plan['stripe_price'] ?: null,
            description: $plan['description'],
            limits: $plan['limits'],
            features: $plan['features'],
            comparison: $plan['comparison'],
        );
    }
}
