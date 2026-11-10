<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingMode;
use App\Models\BillingWebhookEvent;
use App\Models\Product;
use App\Models\User;
use App\Services\Billing\BillingManager;
use App\Services\Billing\DemoBillingProvider;
use App\Services\Billing\StripeBillingProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Subscription;
use Stripe\ApiRequestor;
use Tests\Concerns\UsesBilling;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

class StripeBillingTest extends TestCase
{
    use RefreshDatabase, UsesBilling;

    private const SECRET = 'whsec_test_secret';

    private User $user;

    private FakeStripeHttpClient $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useRealDefaultPlan();

        config([
            'billing.provider' => 'stripe',
            'billing.mode' => 'test',
            'cashier.key' => 'pk_test_placeholder',
            'cashier.secret' => 'sk_test_placeholder',
            'cashier.webhook.secret' => self::SECRET,
            'billing.plans.starter.stripe_price' => 'price_starter',
            'billing.plans.pro.stripe_price' => 'price_pro',
            'billing.plans.agency.stripe_price' => 'price_agency',
        ]);

        $this->stripe = new FakeStripeHttpClient;
        ApiRequestor::setHttpClient($this->stripe);

        $this->user = User::factory()->create();
        $this->user->forceFill(['stripe_id' => 'cus_1'])->save();
    }

    private function provider(): StripeBillingProvider
    {
        return app(StripeBillingProvider::class);
    }

    private function subscription(string $status = 'active', array $overrides = [], string $price = 'price_pro'): array
    {
        return array_replace([
            'id' => 'sub_1',
            'object' => 'subscription',
            'customer' => 'cus_1',
            'status' => $status,
            'metadata' => ['type' => 'default'],
            'cancel_at_period_end' => false,
            'trial_end' => null,
            'items' => ['object' => 'list', 'data' => [['id' => 'si_1', 'object' => 'subscription_item', 'quantity' => 1, 'price' => ['id' => $price, 'object' => 'price', 'product' => 'prod_1']]]],
        ], $overrides);
    }

    private function event(string $id, string $type, array $object, int $created = 1000): string
    {
        return json_encode(['id' => $id, 'object' => 'event', 'type' => $type, 'created' => $created, 'data' => ['object' => $object]], JSON_THROW_ON_ERROR);
    }

    private function sign(string $payload, string $secret = self::SECRET): string
    {
        $timestamp = time();

        return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
    }

    private function deliver(string $payload, ?string $signature = null)
    {
        return $this->call('POST', '/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature ?? $this->sign($payload)], $payload);
    }

    private function activate(string $price = 'price_pro'): void
    {
        $this->deliver($this->event('evt_created', 'customer.subscription.created', $this->subscription(price: $price), 1000))->assertOk();
    }

    public function test_the_mode_is_labeled(): void
    {
        $this->assertSame(BillingMode::StripeTest, $this->provider()->mode());
        $this->assertNull($this->provider()->unavailableReason());
        $this->stripe->responses['GET /v1/invoices'] = ['object' => 'list', 'data' => [], 'has_more' => false];

        $this->actingAs($this->user)->get(route('billing.index'))->assertOk()->assertSee('Stripe Test Mode');
    }

    public function test_live_mode_needs_an_explicit_flag_and_live_keys(): void
    {
        config(['billing.mode' => 'live']);
        $this->assertSame('Live billing isn\'t enabled on this server.', $this->provider()->unavailableReason());

        config(['billing.allow_live' => true]);
        $this->assertNotNull($this->provider()->unavailableReason(), 'A test key must not run in live mode.');

        config(['cashier.secret' => 'sk_live_placeholder']);
        $this->assertNull($this->provider()->unavailableReason());
        $this->assertSame(BillingMode::StripeLive, $this->provider()->mode());
    }

    public function test_live_keys_are_refused_in_test_mode(): void
    {
        config(['cashier.secret' => 'sk_live_placeholder']);

        $this->assertNotNull($this->provider()->unavailableReason());

        $this->actingAs($this->user)->post(route('billing.plans.change', 'pro'), ['confirm' => '1'])->assertSessionHasErrors('billing');
        $this->assertSame([], $this->stripe->requests);
    }

    public function test_missing_configuration_blocks_actions_and_reveals_nothing(): void
    {
        foreach (['cashier.key', 'cashier.secret', 'cashier.webhook.secret', 'billing.plans.pro.stripe_price'] as $setting) {
            $original = config($setting);
            config([$setting => null]);

            $this->assertSame('Stripe billing isn\'t fully configured on this server.', $this->provider()->unavailableReason(), $setting);

            config([$setting => $original]);
        }
    }

    public function test_checkout_uses_the_server_side_price_and_grants_nothing(): void
    {
        $this->stripe->responses['GET /v1/customers/cus_1'] = ['id' => 'cus_1', 'object' => 'customer'];
        $this->stripe->responses['POST /v1/checkout/sessions'] = ['id' => 'cs_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.test/session'];

        $this->actingAs($this->user)
            ->post(route('billing.plans.change', 'pro'), ['confirm' => '1', 'price' => 'price_fake', 'stripe_price' => 'price_fake'])
            ->assertRedirect('https://checkout.stripe.test/session');

        $this->assertSame('price_pro', collect($this->stripe->requests)->firstWhere('path', '/v1/checkout/sessions')['params']['line_items'][0]['price']);
        $this->assertSame('free', $this->planOf($this->user));
    }

    public function test_the_checkout_success_url_grants_nothing(): void
    {
        $this->actingAs($this->user)->get(route('billing.checkout.return', ['result' => 'success', 'plan' => 'agency']))
            ->assertRedirect(route('billing.index'));

        $this->assertSame('free', $this->planOf($this->user));
        $this->assertSame(0, $this->user->subscriptions()->count());
    }

    public function test_a_signed_event_activates_the_plan(): void
    {
        $this->activate();

        $this->assertSame('pro', $this->planOf($this->user));
        $this->assertSame('active', $this->provider()->state($this->user)->status->value);
    }

    public function test_webhooks_get_past_csrf_protection_outside_tests(): void
    {
        // CSRF checks are skipped in the testing environment, so this leaves it.
        $this->app['env'] = 'production';

        $this->activate();

        $this->assertSame('pro', $this->planOf($this->user));
    }

    public function test_other_billing_posts_still_need_a_csrf_token(): void
    {
        $this->app['env'] = 'production';

        $this->actingAs($this->user)->post(route('billing.cancel'), ['confirm' => '1'])->assertStatus(419);
    }

    public function test_an_unsigned_or_forged_event_is_rejected(): void
    {
        $payload = $this->event('evt_x', 'customer.subscription.created', $this->subscription());

        $this->deliver($payload, 't=1,v1=forged')->assertForbidden();
        $this->deliver($payload, $this->sign($payload, 'whsec_wrong'))->assertForbidden();

        $this->assertSame('free', $this->planOf($this->user));
        $this->assertSame(0, BillingWebhookEvent::query()->count());
    }

    public function test_an_old_signature_is_rejected(): void
    {
        $payload = $this->event('evt_x', 'customer.subscription.created', $this->subscription());
        $old = time() - 3600;

        $this->deliver($payload, "t={$old},v1=".hash_hmac('sha256', "{$old}.{$payload}", self::SECRET))->assertForbidden();
    }

    public function test_webhooks_are_refused_without_a_secret(): void
    {
        config(['cashier.webhook.secret' => null]);

        $this->deliver($this->event('evt_x', 'customer.subscription.created', $this->subscription()), 'unused')->assertServiceUnavailable();

        $this->assertSame('free', $this->planOf($this->user));
    }

    public function test_webhooks_dont_exist_in_demo_mode(): void
    {
        config(['billing.provider' => 'demo']);

        $this->deliver($this->event('evt_x', 'customer.subscription.created', $this->subscription()))->assertNotFound();
    }

    public function test_a_duplicate_delivery_is_processed_once(): void
    {
        $this->activate();

        $payload = $this->event('evt_cancel', 'customer.subscription.updated', $this->subscription('past_due'), 2000);

        $this->deliver($payload)->assertOk();
        Subscription::query()->update(['stripe_status' => 'active']);

        $this->deliver($payload)->assertOk()->assertSee('Already processed.');

        $this->assertSame('active', Subscription::query()->sole()->stripe_status);
        $this->assertSame(2, BillingWebhookEvent::query()->count());
    }

    public function test_an_older_event_cant_undo_a_newer_one(): void
    {
        $this->activate();

        $this->deliver($this->event('evt_new', 'customer.subscription.updated', $this->subscription('past_due'), 3000))->assertOk();
        $this->deliver($this->event('evt_old', 'customer.subscription.updated', $this->subscription('active'), 2000))->assertOk()->assertSee('Ignored');

        $this->assertSame('past_due', Subscription::query()->sole()->stripe_status);
        $this->assertSame(BillingWebhookEvent::IGNORED, BillingWebhookEvent::query()->where('stripe_event_id', 'evt_old')->value('status'));
    }

    public function test_a_late_update_cant_revive_a_deleted_subscription(): void
    {
        $this->activate();

        $this->deliver($this->event('evt_deleted', 'customer.subscription.deleted', $this->subscription('canceled'), 3000))->assertOk();
        $this->deliver($this->event('evt_tie', 'customer.subscription.updated', $this->subscription('active'), 3000))->assertOk();

        $this->assertSame('free', $this->planOf($this->user));
    }

    public function test_an_update_arriving_before_created_still_works(): void
    {
        $this->deliver($this->event('evt_update', 'customer.subscription.updated', $this->subscription('active'), 2000))->assertOk();
        $this->deliver($this->event('evt_created', 'customer.subscription.created', $this->subscription('active'), 1000))->assertOk();

        $this->assertSame('pro', $this->planOf($this->user));
        $this->assertSame(1, Subscription::query()->count());
    }

    public function test_a_failed_handler_lets_stripe_retry(): void
    {
        $payload = $this->event('evt_bad', 'customer.subscription.updated', [...$this->subscription(), 'items' => null]);

        $this->withoutExceptionHandling();

        try {
            $this->deliver($payload);
            $this->fail('The handler should have failed.');
        } catch (\Throwable) {
            $this->assertSame(0, BillingWebhookEvent::query()->count());
        }
    }

    public function test_an_unknown_price_grants_nothing(): void
    {
        $this->activate('price_unknown');

        $this->assertSame('free', $this->planOf($this->user));
    }

    public function test_a_failed_payment_has_a_grace_period(): void
    {
        $this->activate();

        $this->deliver($this->event('evt_due', 'customer.subscription.updated', $this->subscription('past_due'), 2000))->assertOk();

        $this->assertNotNull(Subscription::query()->sole()->past_due_at);
        $this->assertSame('pro', $this->planOf($this->user));

        $this->travel((int) config('billing.past_due_grace_days') + 1)->days();

        $this->assertSame('free', $this->planOf($this->user));
        $this->assertSame('past_due', $this->provider()->state($this->user)->status->value);
    }

    public function test_a_recovered_payment_clears_the_grace_period(): void
    {
        $this->activate();
        $this->deliver($this->event('evt_due', 'customer.subscription.updated', $this->subscription('past_due'), 2000));

        $this->deliver($this->event('evt_paid', 'customer.subscription.updated', $this->subscription('active'), 3000));

        $this->assertNull(Subscription::query()->sole()->past_due_at);
        $this->assertSame('active', $this->provider()->state($this->user)->status->value);
    }

    public function test_unpaid_and_incomplete_subscriptions_grant_nothing(): void
    {
        foreach (['unpaid', 'incomplete'] as $index => $status) {
            Subscription::query()->delete();
            $this->deliver($this->event("evt_{$status}", 'customer.subscription.created', $this->subscription($status), 5000 + $index));

            $this->assertSame('free', $this->planOf($this->user), $status);
        }
    }

    public function test_cancellation_lasts_until_the_period_ends(): void
    {
        $this->activate();
        $end = now()->addDays(10)->timestamp;
        $this->stripe->responses['GET /v1/subscription_items/si_1'] = ['id' => 'si_1', 'object' => 'subscription_item', 'current_period_end' => $end];

        $this->deliver($this->event('evt_cancel', 'customer.subscription.updated', $this->subscription('active', ['cancel_at_period_end' => true, 'current_period_end' => $end, 'cancel_at' => $end, 'items' => ['object' => 'list', 'data' => [['id' => 'si_1', 'quantity' => 1, 'current_period_end' => $end, 'price' => ['id' => 'price_pro', 'product' => 'prod_1']]]]]), 2000))->assertOk();

        $this->assertSame('canceling', $this->provider()->state($this->user)->status->value);
        $this->assertSame('pro', $this->planOf($this->user));

        $this->travel(11)->days();

        $this->assertSame('free', $this->planOf($this->user));
    }

    public function test_a_deleted_subscription_keeps_user_data(): void
    {
        $product = Product::factory()->for($this->user)->create();
        $this->activate();

        $this->deliver($this->event('evt_deleted', 'customer.subscription.deleted', $this->subscription('canceled'), 3000))->assertOk();

        $this->assertSame('free', $this->planOf($this->user));
        $this->assertNotNull($product->fresh());
    }

    public function test_upgrades_swap_the_existing_subscription(): void
    {
        $this->activate();

        $updated = $this->subscription('active', price: 'price_agency');
        $this->stripe->responses['GET /v1/subscriptions/sub_1'] = $updated;
        $this->stripe->responses['POST /v1/subscriptions/sub_1'] = $updated;

        $this->actingAs($this->user)->post(route('billing.plans.change', 'agency'), ['confirm' => '1'])->assertSessionHas('status');

        $this->assertSame('agency', $this->planOf($this->user));
        $this->assertNotContains('POST /v1/checkout/sessions', $this->stripe->paths());
    }

    public function test_stripe_errors_never_reach_the_user(): void
    {
        $this->actingAs($this->user)->post(route('billing.plans.change', 'pro'), ['confirm' => '1'])
            ->assertSessionHasErrors(['billing' => 'Stripe couldn\'t complete this. Nothing was changed. Please try again.']);
    }

    public function test_another_customers_invoice_is_not_found(): void
    {
        $this->stripe->responses['GET /v1/invoices/in_other'] = ['id' => 'in_other', 'object' => 'invoice', 'customer' => 'cus_someone_else', 'status' => 'paid', 'total' => 100, 'currency' => 'eur', 'created' => 1000];

        $this->actingAs($this->user)->get(route('billing.records.show', 'in_other'))->assertNotFound();
    }

    public function test_a_users_own_invoice_opens_at_stripe(): void
    {
        $this->stripe->responses['GET /v1/invoices/in_mine'] = ['id' => 'in_mine', 'object' => 'invoice', 'customer' => 'cus_1', 'status' => 'paid', 'total' => 2400, 'currency' => 'eur', 'created' => 1000, 'hosted_invoice_url' => 'https://invoice.stripe.test/in_mine'];

        $this->actingAs($this->user)->get(route('billing.records.show', 'in_mine'))->assertRedirect('https://invoice.stripe.test/in_mine');
    }

    public function test_the_billing_portal_is_available_to_customers(): void
    {
        $this->stripe->responses['POST /v1/billing_portal/sessions'] = ['id' => 'bps_1', 'object' => 'billing_portal.session', 'url' => 'https://billing.stripe.test/portal'];

        $this->actingAs($this->user)->get(route('billing.portal'))->assertRedirect('https://billing.stripe.test/portal');
    }

    public function test_reconcile_does_nothing_without_stripe_billing(): void
    {
        config(['billing.provider' => 'demo']);

        $this->artisan('billing:reconcile')->assertSuccessful();

        $this->assertSame([], $this->stripe->requests);
        $this->assertInstanceOf(DemoBillingProvider::class, app(BillingManager::class)->provider());
    }
}
