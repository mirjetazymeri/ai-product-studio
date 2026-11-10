<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Services\Billing\BillingManager;
use App\Services\Billing\PlanEntitlementService;
use App\Services\Billing\SubscriptionPlanService;
use App\Services\Billing\UsageMeteringService;

trait UsesBilling
{
    /**
     * phpunit.xml gives older tests a generous default. Billing tests use the real one.
     */
    protected function useRealDefaultPlan(): void
    {
        config(['billing.default_plan' => 'free']);
    }

    protected function subscribeTo(User $user, string $plan): void
    {
        app(BillingManager::class)->demo()->changePlan($user, app(SubscriptionPlanService::class)->find($plan));
    }

    protected function planOf(User $user): string
    {
        return app(PlanEntitlementService::class)->plan($user)->key;
    }

    protected function used(User $user): int
    {
        return app(UsageMeteringService::class)->used($user);
    }

    protected function setPlanLimit(string $plan, string $limit, int $value): void
    {
        config(["billing.plans.{$plan}.limits.{$limit}" => $value]);
    }
}
