<?php

namespace Tests\Feature\Billing;

use App\Enums\WooCommerceError;
use App\Models\AiGeneration;
use App\Models\Product;
use App\Models\ProductContent;
use App\Models\User;
use App\Services\AI\BulkGenerationService;
use App\Services\AI\GenerationPreferences;
use App\Services\Billing\BillingManager;
use App\Services\Billing\Exceptions\PlanRestrictionException;
use App\Services\Billing\PlanEntitlementService;
use App\Services\ProductService;
use App\Services\WooCommerce\Demo\WooCommerceDemoService;
use App\Services\WooCommerce\Exceptions\WooCommerceException;
use App\Services\WooCommerce\WooCommerceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakesWooCommerce;
use Tests\Concerns\UsesApiTokens;
use Tests\Concerns\UsesBilling;
use Tests\TestCase;

class PlanEnforcementTest extends TestCase
{
    use FakesWooCommerce, RefreshDatabase, UsesApiTokens, UsesBilling;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useRealDefaultPlan();
        $this->user = User::factory()->create();
    }

    public function test_the_product_limit_is_enforced_on_the_web(): void
    {
        $this->setPlanLimit('free', 'products', 1);
        Product::factory()->for($this->user)->create();

        $this->actingAs($this->user)->post(route('products.store'), ['original_name' => 'Over', 'status' => 'draft'])
            ->assertRedirect(route('billing.index'))
            ->assertSessionHas('plan_notice');

        $this->get(route('products.create'))->assertRedirect(route('billing.index'));
        $this->assertSame(1, $this->user->products()->count());
    }

    public function test_the_product_limit_is_enforced_at_the_service_level(): void
    {
        $this->setPlanLimit('free', 'products', 0);

        $this->expectException(PlanRestrictionException::class);

        app(ProductService::class)->create($this->user, ['original_name' => 'Direct']);
    }

    public function test_the_product_limit_is_enforced_on_the_api(): void
    {
        $this->subscribeTo($this->user, 'pro');
        $this->setPlanLimit('pro', 'products', 1);
        $token = $this->tokenFor($this->user);

        $this->api($token)->postJson('/api/v1/products', ['original_name' => 'One'])->assertCreated();

        $this->api($token)->postJson('/api/v1/products', ['original_name' => 'Two'])
            ->assertForbidden()
            ->assertJsonPath('code', 'plan_limit_reached')
            ->assertJsonPath('feature', 'products.create');
    }

    public function test_a_file_import_stops_at_the_product_limit_and_reports_why(): void
    {
        Storage::fake('local');
        $this->setPlanLimit('free', 'products', 1);

        $csv = "Name,Category\nFirst,Toys\nSecond,Toys\n";

        $this->actingAs($this->user)->post(route('catalog.imports.store'), ['file' => UploadedFile::fake()->createWithContent('a.csv', $csv)])->assertRedirect();
        $transfer = $this->user->catalogTransfers()->latest('id')->first();

        $this->post(route('catalog.imports.mapping.save', $transfer), ['mapping' => $transfer->options['suggested'], 'source' => 'test', 'default_language' => 'en'])->assertSessionHasNoErrors();
        $this->post(route('catalog.imports.confirm', $transfer), ['strategy' => 'skip']);

        $transfer->refresh();
        $this->assertSame(1, $this->user->products()->count());
        $this->assertStringContainsString('included in your Free plan', (string) $transfer->rows()->where('result', 'failed')->value('result_message'));
    }

    public function test_the_import_row_limit_applies(): void
    {
        Storage::fake('local');
        $this->setPlanLimit('free', 'import_rows_per_file', 1);

        $csv = "Name\nFirst\nSecond\n";

        $this->actingAs($this->user)->post(route('catalog.imports.store'), ['file' => UploadedFile::fake()->createWithContent('a.csv', $csv)]);
        $transfer = $this->user->catalogTransfers()->latest('id')->first();

        $this->post(route('catalog.imports.mapping.save', $transfer), ['mapping' => $transfer->options['suggested'], 'source' => 'test', 'default_language' => 'en'])
            ->assertSessionHasErrors();

        $this->assertSame(0, $this->user->products()->count());
    }

    public function test_bulk_generation_is_refused_on_free(): void
    {
        $this->actingAs($this->user)->get(route('ai.bulk.create', ['all_matching' => 1]))->assertRedirect(route('billing.index'));

        $this->post(route('ai.bulk.store'), ['product_ids' => [1], 'languages' => ['en'], 'submission_token' => '33333333-3333-4333-8333-333333333333', 'confirm' => '1'])
            ->assertRedirect(route('billing.index'));
    }

    public function test_starters_batch_size_is_limited(): void
    {
        $this->subscribeTo($this->user, 'starter');
        $this->setPlanLimit('starter', 'bulk_items_per_batch', 1);
        $products = Product::factory()->for($this->user)->count(2)->create(['details' => 'x']);

        $this->actingAs($this->user)->post(route('ai.bulk.store'), ['product_ids' => $products->modelKeys(), 'languages' => ['en'], 'submission_token' => '44444444-4444-4444-8444-444444444444', 'confirm' => '1'])
            ->assertRedirect(route('billing.index'))
            ->assertSessionHas('plan_notice');
    }

    public function test_a_queued_batch_stops_when_the_plan_lapses(): void
    {
        Queue::fake();
        Http::fake();
        $this->subscribeTo($this->user, 'pro');
        $product = Product::factory()->for($this->user)->create(['details' => 'x']);

        $batch = app(BulkGenerationService::class)->start($this->user, [$product->id], ['en'], new GenerationPreferences, '55555555-5555-4555-8555-555555555555');

        app(BillingManager::class)->demo()->restoreFree($this->user);

        $this->assertNull(app(BulkGenerationService::class)->processNext($batch->id));

        $this->assertSame('plan_feature_unavailable', $batch->items()->sole()->error_code);
        Http::assertNothingSent();
    }

    public function test_real_stores_need_the_plan_on_the_web(): void
    {
        $store = $this->connectedStore($this->user);

        $this->actingAs($this->user)->get(route('integrations.create'))->assertRedirect(route('billing.index'));
        $this->post(route('integrations.test', $store))->assertRedirect(route('billing.index'));
        $this->get(route('integrations.imports.create', $store))->assertRedirect(route('billing.index'));
    }

    public function test_store_requests_are_refused_below_the_controllers_too(): void
    {
        Http::fake();
        $store = $this->connectedStore($this->user);

        try {
            app(WooCommerceService::class)->getProduct($store, 1);
            $this->fail('The request should have been refused.');
        } catch (WooCommerceException $exception) {
            $this->assertSame(WooCommerceError::PlanRequired, $exception->error);
        }

        Http::assertNothingSent();
    }

    public function test_a_downgrade_keeps_every_store_and_its_pages(): void
    {
        $this->subscribeTo($this->user, 'pro');
        $store = $this->connectedStore($this->user);
        $link = $this->linkedProduct($this->user, $store);

        app(BillingManager::class)->demo()->restoreFree($this->user);

        $this->actingAs($this->user)->get(route('integrations.index'))->assertOk()->assertSee($store->name);
        $this->get(route('integrations.products', $store))->assertOk();
        $this->get(route('integrations.sync.history'))->assertOk();
        $this->assertSame('connected', $store->fresh()->status);
        $this->assertNotNull($store->fresh()->consumer_key);
        $this->assertNotNull($link->fresh());

        $this->post(route('integrations.disconnect', $store), ['confirm' => '1'])->assertRedirect(route('integrations.index'));
    }

    public function test_woocommerce_demo_mode_works_on_the_free_plan(): void
    {
        Http::fake();
        $store = app(WooCommerceDemoService::class)->activate($this->user);

        $this->assertSame('connected', $store->status);

        $this->actingAs($this->user)->post(route('integrations.imports.store', $store), ['language' => 'en'])->assertRedirect();
        $this->post(route('integrations.imports.confirm', $this->user->catalogTransfers()->latest('id')->first()), ['import_new' => '1']);

        $this->assertSame(13, $store->links()->count());
        Http::assertNothingSent();
    }

    public function test_demo_store_products_dont_use_the_product_limit(): void
    {
        Http::fake();
        $this->setPlanLimit('free', 'products', 0);
        $store = app(WooCommerceDemoService::class)->activate($this->user);

        $this->actingAs($this->user)->post(route('integrations.imports.store', $store), ['language' => 'en']);
        $this->post(route('integrations.imports.confirm', $this->user->catalogTransfers()->latest('id')->first()), ['import_new' => '1']);

        $this->assertSame(13, $store->links()->count());
    }

    public function test_api_tokens_cant_bypass_the_plan(): void
    {
        $token = $this->tokenFor($this->user);

        $this->api($token)->getJson('/api/v1/products')
            ->assertForbidden()
            ->assertJsonPath('code', 'plan_feature_unavailable')
            ->assertJsonPath('feature', 'api.access')
            ->assertJsonPath('plan', 'free');

        $this->api($token)->postJson('/api/v1/exports', ['format' => 'csv'])->assertForbidden();
    }

    public function test_abilities_and_plan_are_both_needed(): void
    {
        $this->subscribeTo($this->user, 'pro');

        $this->api($this->tokenFor($this->user, ['content:read']))->getJson('/api/v1/products')->assertForbidden()->assertJsonPath('code', 'insufficient_ability');
        $this->api($this->tokenFor($this->user, ['products:read']))->getJson('/api/v1/products')->assertOk();
    }

    public function test_expiration_is_enforced_on_existing_tokens(): void
    {
        $this->subscribeTo($this->user, 'pro');
        $token = $this->tokenFor($this->user);

        $this->api($token)->getJson('/api/v1/products')->assertOk();

        app(BillingManager::class)->demo()->simulateExpiration($this->user);

        $this->api($token)->getJson('/api/v1/products')->assertForbidden();
    }

    public function test_me_reports_the_plan_without_billing_identifiers(): void
    {
        $this->user->forceFill(['stripe_id' => 'cus_private', 'pm_last_four' => '4242'])->save();
        $this->subscribeTo($this->user, 'pro');

        $this->api($this->tokenFor($this->user))->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.subscription.plan', 'pro')
            ->assertJsonPath('data.subscription.ai_generations_limit', 300)
            ->assertDontSee('cus_private')
            ->assertDontSee('DEMO-SUB')
            ->assertDontSee('4242');
    }

    public function test_me_stays_open_on_the_free_plan(): void
    {
        $this->api($this->tokenFor($this->user))->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.subscription.plan', 'free');
    }

    public function test_api_token_creation_needs_the_plan_but_revocation_doesnt(): void
    {
        $this->tokenFor($this->user);

        $this->actingAs($this->user)->get(route('settings.edit'))->assertOk()->assertSee('Compare plans');
        $this->post(route('settings.api-tokens.store'), ['name' => 'x', 'abilities' => ['ai:read']])->assertRedirect(route('billing.index'));

        $this->delete(route('settings.api-tokens.destroy', $this->user->tokens()->sole()->id))->assertRedirect();
        $this->assertSame(0, $this->user->tokens()->count());
    }

    public function test_excess_products_stay_readable_after_a_downgrade(): void
    {
        $this->subscribeTo($this->user, 'pro');
        $products = Product::factory()->for($this->user)->count(25)->create();
        $content = ProductContent::factory()->for($products->first())->ai()->create(['language' => 'en', 'title' => 'Kept']);
        $generation = AiGeneration::factory()->for($this->user)->create();

        app(BillingManager::class)->demo()->restoreFree($this->user);

        $this->assertSame('free', $this->planOf($this->user));
        $this->assertSame(25, $this->user->products()->count());
        $this->assertNotNull($generation->fresh());

        $this->actingAs($this->user)->get(route('products.index'))->assertOk();
        $this->get(route('products.show', $products->last()))->assertOk();
        $this->get(route('products.show', $products->first()))->assertSee('Kept');
        $this->get(route('generation-history'))->assertOk();
        $this->get(route('dashboard'))->assertOk();
        $this->assertSame('Kept', $content->fresh()->title);

        $this->patch(route('products.update', $products->first()), ['original_name' => 'Edited', 'status' => 'draft'])->assertRedirect(route('products.show', $products->first()));
        $this->post(route('products.store'), ['original_name' => 'New', 'status' => 'draft'])->assertRedirect(route('billing.index'));
    }

    public function test_essential_pages_work_when_everything_is_used_up(): void
    {
        $this->setPlanLimit('free', 'products', 0);
        $this->setPlanLimit('free', 'ai_generations', 0);

        $this->actingAs($this->user);

        foreach (['dashboard', 'products.index', 'settings.edit', 'billing.index', 'pricing', 'catalog.history'] as $route) {
            $this->get(route($route))->assertOk();
        }

        $this->assertFalse(app(PlanEntitlementService::class)->can($this->user, 'api.access'));
    }
}
