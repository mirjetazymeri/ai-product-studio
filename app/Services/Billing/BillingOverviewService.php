<?php

namespace App\Services\Billing;

use App\Models\User;

/**
 * The figures shown on the dashboard and billing page, and returned by the API.
 */
class BillingOverviewService
{
    public function __construct(
        private readonly PlanEntitlementService $entitlements,
        private readonly UsageMeteringService $usage,
        private readonly BillingManager $billing,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        $state = $this->entitlements->state($user);
        $plan = $state->plan;
        $aiLimit = $plan->limit('ai_generations');
        $aiUsed = $this->usage->used($user);
        $productLimit = $plan->limit('products');
        $products = $user->products()->count();

        return [
            'state' => $state,
            'plan' => $plan,
            'mode' => $this->billing->provider()->mode(),
            'features' => $this->entitlements->featureList($plan),
            'period' => ['key' => $this->usage->currentPeriod(), 'resets_at' => $this->usage->periodEnd()],
            'ai' => ['used' => $aiUsed, 'limit' => $aiLimit, 'remaining' => max(0, $aiLimit - $aiUsed), 'percent' => $this->percent($aiUsed, $aiLimit)],
            'products' => ['used' => $products, 'limit' => $productLimit, 'remaining' => max(0, $productLimit - $products), 'percent' => $this->percent($products, $productLimit)],
        ];
    }

    private function percent(int $used, int $limit): int
    {
        return $limit <= 0 ? 100 : min(100, (int) round($used / $limit * 100));
    }
}
