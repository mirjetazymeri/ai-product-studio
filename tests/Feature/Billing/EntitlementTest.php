<?php

namespace Tests\Feature\Billing;

use App\Models\AiGeneration;
use App\Models\Product;
use App\Models\User;
use App\Services\Billing\BillingOverviewService;
use App\Services\Billing\PlanEntitlementService;
use App\Services\Billing\SubscriptionPlanService;
use App\Services\Billing\UsageMeteringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesBilling;
use Tests\TestCase;

class EntitlementTest extends TestCase
{
    use RefreshDatabase, UsesBilling;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useRealDefaultPlan();
        $this->user = User::factory()->create();
    }

    private function entitlements(): PlanEntitlementService
    {
        return app(PlanEntitlementService::class);
    }

    public function test_the_shipped_default_plan_is_free(): void
    {
        $this->assertStringContainsString("env('BILLING_DEFAULT_PLAN', 'free')", (string) file_get_contents(config_path('billing.php')));
        $this->assertStringContainsString("env('BILLING_PROVIDER', 'demo')", (string) file_get_contents(config_path('billing.php')));
    }

    public function test_new_users_are_on_the_free_plan_with_no_payment_data(): void
    {
        $state = $this->entitlements()->state($this->user);

        $this->assertSame('free', $state->plan->key);
        $this->assertSame('free', $state->status->value);
        $this->assertNull($this->user->stripe_id);
        $this->assertSame(0, $this->user->subscriptions()->count());
    }

    public function test_all_four_plans_exist_with_the_specified_limits(): void
    {
        $plans = collect(app(SubscriptionPlanService::class)->all())->keyBy('key');

        $this->assertSame(['free', 'starter', 'pro', 'agency'], $plans->keys()->all());
        $this->assertSame([0, 900, 2400, 5900], $plans->map->priceCents->values()->all());
        $this->assertSame([10, 50, 300, 1000], $plans->map(fn ($plan) => $plan->limit('ai_generations'))->values()->all());
        $this->assertSame([20, 200, 2000, 10000], $plans->map(fn ($plan) => $plan->limit('products'))->values()->all());
    }

    public function test_features_resolve_per_plan(): void
    {
        $expected = [
            'free' => ['ai.generate' => true, 'ai.bulk' => false, 'integrations.woocommerce' => false, 'api.access' => false],
            'starter' => ['ai.generate' => true, 'ai.bulk' => true, 'integrations.woocommerce' => false, 'api.access' => false],
            'pro' => ['ai.generate' => true, 'ai.bulk' => true, 'integrations.woocommerce' => true, 'api.access' => true],
            'agency' => ['ai.generate' => true, 'ai.bulk' => true, 'integrations.woocommerce' => true, 'api.access' => true],
        ];

        foreach ($expected as $plan => $features) {
            if ($plan !== 'free') {
                $this->subscribeTo($this->user, $plan);
            }

            foreach ($features as $feature => $allowed) {
                $this->assertSame($allowed, $this->entitlements()->can($this->user, $feature), "{$plan} {$feature}");
            }
        }
    }

    public function test_coming_soon_features_are_never_granted(): void
    {
        $this->subscribeTo($this->user, 'agency');

        $this->assertFalse($this->entitlements()->can($this->user, 'integrations.shopify'));
        $this->assertFalse($this->entitlements()->can($this->user, 'agency.tools'));
        $this->assertStringContainsString('coming soon', (string) $this->entitlements()->denialReason($this->user, 'integrations.shopify'));
    }

    public function test_a_denial_explains_itself(): void
    {
        $this->assertStringContainsString('Free', (string) $this->entitlements()->denialReason($this->user, 'api.access'));
        $this->assertNull($this->entitlements()->denialReason($this->user, 'ai.generate'));
    }

    public function test_plan_definitions_are_config_only(): void
    {
        config(['billing.plans.free.features' => ['api.access']]);

        $this->assertTrue($this->entitlements()->can($this->user, 'api.access'));
    }

    public function test_an_invalid_default_falls_back_to_free(): void
    {
        config(['billing.default_plan' => 'nonsense']);

        $this->assertSame('free', $this->planOf($this->user));
    }

    public function test_existing_accounts_keep_their_data_and_history_isnt_counted(): void
    {
        $product = Product::factory()->for($this->user)->create();
        AiGeneration::factory()->for($this->user)->count(30)->create(['total_tokens' => 100, 'created_at' => now()]);

        $this->assertSame('free', $this->planOf($this->user));
        $this->assertSame(0, $this->used($this->user));
        $this->assertSame(10, app(UsageMeteringService::class)->remaining($this->user));
        $this->assertNotNull($product->fresh());
        $this->assertSame(3000, (int) AiGeneration::query()->sum('total_tokens'));
    }

    public function test_the_overview_reports_usage(): void
    {
        Product::factory()->for($this->user)->count(5)->create();

        $overview = app(BillingOverviewService::class)->for($this->user);

        $this->assertSame(['used' => 5, 'limit' => 20, 'remaining' => 15, 'percent' => 25], $overview['products']);
        $this->assertSame(10, $overview['ai']['remaining']);
        $this->assertSame(now('UTC')->format('Y-m'), $overview['period']['key']);
    }
}
