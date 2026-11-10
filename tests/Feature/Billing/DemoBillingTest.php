<?php

namespace Tests\Feature\Billing;

use App\Enums\UsageAction;
use App\Models\DemoBillingSubscription;
use App\Models\DemoBillingTransaction;
use App\Models\Product;
use App\Models\UsageEvent;
use App\Models\User;
use App\Services\Billing\BillingManager;
use App\Services\Billing\DemoBillingProvider;
use App\Services\Billing\DisabledBillingProvider;
use App\Services\Billing\UsageMeteringService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\UsesBilling;
use Tests\TestCase;

class DemoBillingTest extends TestCase
{
    use RefreshDatabase, UsesBilling;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useRealDefaultPlan();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    private function change(string $plan, array $extra = [])
    {
        return $this->post(route('billing.plans.change', $plan), ['confirm' => '1', ...$extra]);
    }

    public function test_the_pricing_page_is_public_and_honest(): void
    {
        $this->app['auth']->forgetGuards();
        $this->post('/logout');
        $this->app['auth']->forgetGuards();

        $this->get(route('pricing'))
            ->assertOk()
            ->assertSee('Starter')
            ->assertSee('€24')
            ->assertSee('€59')
            ->assertSee('Coming Soon')
            ->assertSee('illustrative')
            ->assertDontSee('Current plan');
    }

    public function test_the_pricing_page_marks_the_current_plan(): void
    {
        $this->get(route('pricing'))->assertOk()->assertSee('Current plan')->assertSee('Upgrade to Pro');
    }

    public function test_the_billing_page_labels_the_simulation(): void
    {
        $this->get(route('billing.index'))
            ->assertOk()
            ->assertSee('Demo Billing')
            ->assertSee('Simulation')
            ->assertSee('Simulate payment failure')
            ->assertSee('Reset demo billing');
    }

    public function test_a_plan_change_needs_confirmation(): void
    {
        $this->post(route('billing.plans.change', 'pro'))->assertSessionHasErrors('confirm');

        $this->assertSame('free', $this->planOf($this->user));
    }

    public function test_a_simulated_upgrade_changes_entitlements_and_records_a_simulation(): void
    {
        $this->get(route('billing.plans.confirm', 'pro'))->assertOk()->assertSee('no payment will be taken');

        $this->change('pro')->assertRedirect(route('billing.index'))->assertSessionHas('status');

        $this->assertSame('pro', $this->planOf($this->user));

        $transaction = DemoBillingTransaction::query()->sole();
        $this->assertStringStartsWith(DemoBillingProvider::TRANSACTION_PREFIX, $transaction->reference);
        $this->assertSame(2400, $transaction->amount_cents);
        $this->assertSame('simulated', $transaction->status);
        $this->assertStringStartsWith(DemoBillingProvider::SUBSCRIPTION_PREFIX, DemoBillingSubscription::query()->sole()->reference);
        $this->assertNull($this->user->fresh()->stripe_id);
    }

    public function test_submitted_prices_and_unknown_plans_are_ignored(): void
    {
        $this->change('pro', ['price' => '1', 'price_cents' => 1, 'plan' => 'agency']);

        $this->assertSame('pro', $this->planOf($this->user));
        $this->assertSame(2400, DemoBillingTransaction::query()->sole()->amount_cents);

        $this->change('enterprise')->assertNotFound();
        $this->get(route('billing.plans.confirm', 'enterprise'))->assertNotFound();
    }

    public function test_downgrading_is_a_plan_change(): void
    {
        $this->change('agency');
        $this->change('starter');

        $this->assertSame('starter', $this->planOf($this->user));
    }

    public function test_cancellation_keeps_access_until_the_period_ends(): void
    {
        $this->change('pro');

        $this->post(route('billing.cancel'), ['confirm' => '1'])->assertSessionHas('status');

        $state = app(BillingManager::class)->provider()->state($this->user);
        $this->assertSame('canceling', $state->status->value);
        $this->assertSame('pro', $state->plan->key);
        $this->assertTrue($state->canResume());

        $this->travel(32)->days();

        $state = app(BillingManager::class)->provider()->state($this->user);
        $this->assertSame('expired', $state->status->value);
        $this->assertSame('free', $state->plan->key);
    }

    public function test_cancellation_needs_confirmation(): void
    {
        $this->change('pro');

        $this->post(route('billing.cancel'))->assertSessionHasErrors('confirm');

        $this->assertSame('active', DemoBillingSubscription::query()->sole()->status->value);
    }

    public function test_a_canceling_subscription_can_be_resumed(): void
    {
        $this->change('pro');
        $this->post(route('billing.cancel'), ['confirm' => '1']);

        $this->post(route('billing.resume'))->assertSessionHas('status');

        $this->assertSame('active', app(BillingManager::class)->provider()->state($this->user)->status->value);
    }

    public function test_choosing_free_cancels_a_paid_plan(): void
    {
        $this->change('pro');

        $this->change('free')->assertSessionHas('status');

        $this->assertSame('canceling', app(BillingManager::class)->provider()->state($this->user)->status->value);
    }

    public function test_a_payment_failure_starts_a_grace_period_then_ends_access(): void
    {
        $this->change('pro');

        $this->post(route('billing.demo.payment-failure'))->assertSessionHas('status');

        $state = app(BillingManager::class)->provider()->state($this->user);
        $this->assertSame('past_due', $state->status->value);
        $this->assertSame('pro', $state->plan->key);
        $this->get(route('billing.index'))->assertSee('A payment failed.');

        $this->travel((int) config('billing.past_due_grace_days') + 1)->days();

        $state = app(BillingManager::class)->provider()->state($this->user);
        $this->assertSame('past_due', $state->status->value);
        $this->assertSame('free', $state->plan->key);
    }

    public function test_a_recovered_payment_restores_the_plan(): void
    {
        $this->change('pro');
        $this->post(route('billing.demo.payment-failure'));

        $this->post(route('billing.demo.payment-recovery'))->assertSessionHas('status');

        $this->assertSame('active', app(BillingManager::class)->provider()->state($this->user)->status->value);
    }

    public function test_expiration_removes_paid_access_only(): void
    {
        $product = Product::factory()->for($this->user)->create();
        $this->change('pro');

        $this->post(route('billing.demo.expire'))->assertSessionHas('status');

        $this->assertSame('free', $this->planOf($this->user));
        $this->assertNotNull($product->fresh());
    }

    public function test_actions_without_a_subscription_are_refused(): void
    {
        $this->post(route('billing.demo.payment-failure'))->assertSessionHasErrors('billing');
        $this->post(route('billing.demo.expire'))->assertSessionHasErrors('billing');
        $this->post(route('billing.cancel'), ['confirm' => '1'])->assertSessionHasErrors('billing');
    }

    public function test_the_free_plan_can_be_restored(): void
    {
        $this->change('pro');

        $this->post(route('billing.demo.restore-free'))->assertSessionHas('status');

        $this->assertSame('free', $this->planOf($this->user));
    }

    public function test_quota_enforcement_can_be_demonstrated_without_gemini(): void
    {
        Http::fake();
        $product = Product::factory()->for($this->user)->create(['details' => 'x']);

        $this->post(route('billing.demo.use-allowance'))->assertSessionHas('status');

        $this->assertSame(10, $this->used($this->user));
        $this->postJson(route('ai.generate', $product), ['language' => 'en'])->assertTooManyRequests()->assertJsonPath('code', 'monthly_limit_reached');
        Http::assertNothingSent();
    }

    public function test_reset_removes_only_this_users_simulation(): void
    {
        $other = User::factory()->create();
        $this->subscribeTo($other, 'pro');
        $product = Product::factory()->for($this->user)->create();

        $this->change('agency');
        app(UsageMeteringService::class)->reserve($this->user, UsageAction::Generation, 'real-work');
        $this->post(route('billing.demo.use-allowance'));

        $this->post(route('billing.demo.reset'))->assertSessionHasErrors('confirm');

        $this->post(route('billing.demo.reset'), ['confirm' => '1'])->assertSessionHas('status');

        $this->assertSame('free', $this->planOf($this->user));
        $this->assertSame(1, $this->used($this->user));
        $this->assertSame(0, DemoBillingTransaction::query()->where('user_id', $this->user->id)->count());
        $this->assertSame('pro', $this->planOf($other));
        $this->assertNotNull($product->fresh());
        $this->assertSame(2, UsageEvent::query()->where('user_id', $this->user->id)->count());
    }

    public function test_records_are_labeled_and_private(): void
    {
        $this->change('pro');
        $reference = DemoBillingTransaction::query()->sole()->reference;

        $this->get(route('billing.index'))->assertSee('Simulated billing history')->assertSee($reference)->assertDontSee('Invoice history');
        $this->get(route('billing.records.show', $reference))->assertOk()->assertSee('DEMO')->assertSee('isn\'t an invoice', false);

        $this->actingAs(User::factory()->create())->get(route('billing.records.show', $reference))->assertNotFound();
    }

    public function test_guests_cant_change_subscriptions(): void
    {
        $this->app['auth']->forgetGuards();
        $this->post('/logout');
        $this->app['auth']->forgetGuards();

        $this->post(route('billing.plans.change', 'pro'), ['confirm' => '1'])->assertRedirect(route('login'));
        $this->post(route('billing.demo.reset'), ['confirm' => '1'])->assertRedirect(route('login'));
        $this->assertSame(0, DemoBillingSubscription::query()->count());
    }

    public function test_demo_is_disabled_in_production(): void
    {
        $this->subscribeTo($this->user, 'agency');
        $this->app['env'] = 'production';
        $this->withoutMiddleware(PreventRequestForgery::class);

        $manager = app(BillingManager::class);

        $this->assertFalse($manager->demoEnabled());
        $this->assertInstanceOf(DisabledBillingProvider::class, $manager->provider());
        $this->assertSame('free', $this->planOf($this->user));

        $this->post(route('billing.demo.reset'), ['confirm' => '1'])->assertNotFound();
        $this->post(route('billing.demo.use-allowance'))->assertNotFound();

        $this->change('pro')->assertSessionHasErrors('billing');
        $this->assertSame('agency', DemoBillingSubscription::query()->sole()->plan);
    }

    public function test_an_isolated_demo_deployment_needs_an_explicit_flag(): void
    {
        $this->app['env'] = 'production';
        config(['billing.allow_demo_in_production' => true]);

        $this->assertTrue(app(BillingManager::class)->demoEnabled());
    }

    public function test_demo_rows_mean_nothing_to_real_billing(): void
    {
        $this->subscribeTo($this->user, 'agency');

        config(['billing.provider' => 'stripe', 'billing.mode' => 'test']);

        $this->assertSame('free', $this->planOf($this->user));
        $this->post(route('billing.demo.expire'))->assertNotFound();
    }

    public function test_a_request_cant_choose_the_provider(): void
    {
        config(['billing.provider' => 'stripe', 'billing.mode' => 'test']);

        $this->get(route('billing.index', ['provider' => 'demo', 'mode' => 'demo']))->assertOk()->assertSee('Stripe Test Mode')->assertDontSee('Simulation controls');
    }

    public function test_the_dashboard_separates_monthly_and_all_time_figures(): void
    {
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('AI generations (all time)')
            ->assertSee('AI generations this month')
            ->assertSee('Free plan');
    }
}
