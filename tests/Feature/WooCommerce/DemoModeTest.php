<?php

namespace Tests\Feature\WooCommerce;

use App\Enums\AiGenerationStatus;
use App\Enums\BulkItemStatus;
use App\Enums\ContentLanguage;
use App\Enums\ContentSource;
use App\Enums\WooCommerceError;
use App\Jobs\FetchWooCommerceProducts;
use App\Jobs\ProcessWooCommerceSyncRun;
use App\Models\AiGeneration;
use App\Models\BulkGenerationBatch;
use App\Models\BulkGenerationItem;
use App\Models\CatalogTransfer;
use App\Models\Product;
use App\Models\User;
use App\Models\WooCommerceDemoProduct;
use App\Models\WooCommerceProductLink;
use App\Models\WooCommerceStore;
use App\Models\WooCommerceSyncItem;
use App\Models\WooCommerceSyncRun;
use App\Services\AI\GenerationQuota;
use App\Services\AI\ProductContentGenerator;
use App\Services\AI\ProductContentRules;
use App\Services\WooCommerce\Demo\DemoCatalog;
use App\Services\WooCommerce\Demo\WooCommerceDemoContentService;
use App\Services\WooCommerce\Demo\WooCommerceDemoService;
use App\Services\WooCommerce\Exceptions\WooCommerceException;
use App\Services\WooCommerce\Http\StoreUrlGuard;
use App\Services\WooCommerce\Transport\DemoWooCommerceTransport;
use App\Services\WooCommerce\WooCommerceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Tests\Concerns\FakesWooCommerce;
use Tests\TestCase;

class DemoModeTest extends TestCase
{
    use FakesWooCommerce;
    use RefreshDatabase;

    private const BANNER = 'Demo Mode — No Real WooCommerce Connection';

    private const SIMPLE_ID = 900101;

    private const VALID_PRODUCTS = 13;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        // Any request to the outside world would be recorded here and fail the assertion.
        Http::fake();
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();

        parent::tearDown();
    }

    private function demoStore(?User $user = null): WooCommerceStore
    {
        return app(WooCommerceDemoService::class)->activate($user ?? $this->user);
    }

    private function importedStore(?User $user = null): WooCommerceStore
    {
        $user ??= $this->user;
        $store = $this->demoStore($user);

        $this->actingAs($user)->post(route('integrations.imports.store', $store), ['language' => 'en']);
        $this->post(route('integrations.imports.confirm', $this->latestTransfer($user)), ['import_new' => '1']);

        return $store->refresh();
    }

    private function latestTransfer(?User $user = null): CatalogTransfer
    {
        return ($user ?? $this->user)->catalogTransfers()->latest('id')->firstOrFail();
    }

    private function remote(WooCommerceStore $store, int $remoteId = self::SIMPLE_ID): array
    {
        return $store->demoProducts()->where('remote_id', $remoteId)->firstOrFail()->payload;
    }

    private function linkFor(WooCommerceStore $store, int $remoteId = self::SIMPLE_ID): WooCommerceProductLink
    {
        return $store->links()->with('product')->where('remote_id', $remoteId)->firstOrFail();
    }

    /**
     * Generates and approves sample content through the normal review screens.
     */
    private function approveSample(WooCommerceStore $store, int $remoteId = self::SIMPLE_ID): BulkGenerationItem
    {
        $this->post(route('integrations.demo.sample', $store), ['remote_id' => $remoteId])->assertRedirect(route('ai.reviews.index'));

        $item = $this->user->bulkGenerationItems()->latest('id')->firstOrFail();

        $this->post(route('ai.reviews.approve'), ['item_ids' => [$item->id], 'confirm_overwrite' => '1']);

        return $item->refresh();
    }

    private function preview(WooCommerceStore $store, int $remoteId = self::SIMPLE_ID): WooCommerceSyncRun
    {
        $this->post(route('integrations.sync.store', $store), ['link_ids' => [$this->linkFor($store, $remoteId)->id], 'language' => 'en']);

        return $this->user->wooCommerceSyncRuns()->latest('id')->firstOrFail();
    }

    private function confirm(WooCommerceSyncRun $run, array $fields = ['name', 'description', 'short_description']): void
    {
        $item = $run->items()->firstOrFail();

        $this->post(route('integrations.sync.confirm', $run), ['selected' => [$item->id => $fields], 'confirm' => '1']);
    }

    public function test_try_demo_is_offered_and_creates_a_store_without_credentials(): void
    {
        $this->actingAs($this->user)->get(route('integrations.index'))->assertOk()->assertSee('Try WooCommerce Demo');

        $response = $this->post(route('integrations.demo.activate'));

        $store = $this->user->wooCommerceStores()->sole();

        $response->assertRedirect(route('integrations.demo.show', $store));
        $this->assertTrue($store->isDemo());
        $this->assertNull($store->consumer_key);
        $this->assertNull($store->consumer_secret);
        $this->assertFalse($store->isDisconnected());
        $this->assertSame(WooCommerceStore::CONNECTED, $store->status);
        $this->assertSame(count(DemoCatalog::products()), $store->demoProducts()->count());

        $this->get(route('integrations.index'))
            ->assertDontSee('Try WooCommerce Demo')
            ->assertSee('Demo WooCommerce Store')
            ->assertSee('Simulation')
            ->assertSee('Sample catalog');
    }

    public function test_activating_twice_keeps_one_demo_store_and_its_changes(): void
    {
        $store = $this->demoStore();
        $store->demoProducts()->where('remote_id', self::SIMPLE_ID)->firstOrFail()->update(['payload' => [...$this->remote($store), 'name' => 'Changed']]);

        $again = $this->demoStore();

        $this->assertTrue($store->is($again));
        $this->assertSame(1, $this->user->wooCommerceStores()->count());
        $this->assertSame('Changed', $this->remote($store)['name']);
    }

    public function test_the_catalog_has_every_required_data_condition(): void
    {
        $products = collect(DemoCatalog::products());
        $valid = $products->filter(fn ($product) => $product['name'] !== '');

        $this->assertCount(self::VALID_PRODUCTS, $valid);
        $this->assertGreaterThanOrEqual(6, $valid->pluck('categories.0.name')->unique()->count());
        $this->assertTrue($valid->contains(fn ($product) => $product['description'] === '' && $product['short_description'] === ''));
        $this->assertTrue($valid->contains(fn ($product) => $product['sku'] === ''));
        $this->assertTrue($valid->contains(fn ($product) => $product['meta_data'] !== []));
        $this->assertTrue($valid->contains(fn ($product) => $product['type'] === 'variable' && count($product['variations']) > 1));
        $this->assertGreaterThan(2, $valid->pluck('status')->unique()->count());
        $this->assertLessThan($valid->count(), $valid->pluck('name')->unique()->count());
        $this->assertSame($products->count(), $products->pluck('id')->unique()->count());
    }

    public function test_connection_test_is_simulated_and_reports_an_outage(): void
    {
        $store = $this->demoStore();

        $this->actingAs($this->user)->post(route('integrations.demo.test', $store))->assertSessionHas('status');

        $this->post(route('integrations.demo.outage', $store), ['outage' => 1]);
        $this->post(route('integrations.demo.test', $store))->assertSessionHasErrors('connection');
        $this->assertSame(WooCommerceStore::FAILING, $store->refresh()->status);
        $this->assertSame(WooCommerceError::Unavailable->value, $store->last_error_code);

        $this->post(route('integrations.demo.outage', $store), ['outage' => 0]);
        $this->post(route('integrations.demo.test', $store))->assertSessionHas('status');
        $this->assertSame(WooCommerceStore::CONNECTED, $store->refresh()->status);
    }

    public function test_the_transport_paginates_like_a_store(): void
    {
        $store = $this->demoStore();
        $service = app(WooCommerceService::class);

        $first = $service->listProducts($store, 1, 50);
        $second = $service->listProducts($store, 2, 50);

        $this->assertCount(DemoWooCommerceTransport::PAGE_SIZE, $first['products']);
        $this->assertSame(2, $first['total_pages']);
        $this->assertCount(14 - DemoWooCommerceTransport::PAGE_SIZE, $second['products']);
        $this->assertSame([], array_intersect(array_column($first['products'], 'id'), array_column($second['products'], 'id')));
        $this->assertSame([], $service->listProducts($store, 3, 50)['products']);
        $this->assertSame(14, $service->listProducts($store, 1, 1)['total_pages']);
    }

    public function test_the_transport_uses_the_real_error_contract(): void
    {
        $store = $this->demoStore();
        $service = app(WooCommerceService::class);

        try {
            $service->getProduct($store, 12345);
            $this->fail('A missing product should fail.');
        } catch (WooCommerceException $exception) {
            $this->assertSame(WooCommerceError::NotFound, $exception->error);
        }

        try {
            $service->updateProduct($store, self::SIMPLE_ID, ['price' => '1.00']);
            $this->fail('A write with no allowed field should fail.');
        } catch (WooCommerceException $exception) {
            $this->assertSame(WooCommerceError::Rejected, $exception->error);
        }
    }

    public function test_writes_outside_the_allowlist_are_ignored_and_the_timestamp_moves(): void
    {
        $store = $this->demoStore();
        $before = $this->remote($store);

        $this->travelTo('2026-11-20 10:00:00');

        $after = app(WooCommerceService::class)->updateProduct($store, self::SIMPLE_ID, [
            'name' => 'New name',
            'price' => '1.00',
            'sku' => 'HACK',
            'status' => 'draft',
            'stock_quantity' => '0',
        ]);

        $this->assertSame('New name', $after['name']);
        $this->assertSame('2026-11-20T10:00:00', $after['date_modified_gmt']);
        $this->assertSame($before['price'], $after['price']);
        $this->assertSame($before['sku'], $after['sku']);
        $this->assertSame($before['status'], $after['status']);
        $this->assertSame($before['stock_quantity'], $after['stock_quantity']);
    }

    public function test_real_stores_never_use_the_demo_transport_and_the_demo_address_is_refused(): void
    {
        $real = $this->connectedStore($this->user);

        $this->assertFalse($real->isDemo());
        $this->assertTrue((new WooCommerceStore(['connection_type' => WooCommerceStore::TYPE_LIVE]))->isDisconnected());

        $this->expectException(\LogicException::class);
        app(DemoWooCommerceTransport::class)->getProduct($real, 1);
    }

    public function test_the_demo_address_cant_be_used_for_a_real_store(): void
    {
        $this->expectException(WooCommerceException::class);

        app(StoreUrlGuard::class)->normalise(WooCommerceStore::DEMO_URL);
    }

    public function test_demo_stores_cant_be_edited_or_disconnected(): void
    {
        $store = $this->demoStore();

        $this->actingAs($this->user)->get(route('integrations.edit', $store))->assertNotFound();
        $this->put(route('integrations.update', $store), ['name' => 'x', 'url' => 'https://shop.example.com'])->assertNotFound();
        $this->post(route('integrations.disconnect', $store), ['confirm' => '1'])->assertNotFound();
    }

    public function test_import_uses_the_normal_workflow_and_shows_results(): void
    {
        Queue::fake([FetchWooCommerceProducts::class]);

        $store = $this->demoStore();

        $this->actingAs($this->user)->get(route('integrations.imports.create', $store))->assertOk()->assertSee(self::BANNER)->assertSee('Import Demo Products');

        $this->post(route('integrations.imports.store', $store), ['language' => 'en']);
        $transfer = $this->latestTransfer();

        $this->assertSame(self::VALID_PRODUCTS, $transfer->summary['new']);
        $this->assertSame(1, $transfer->summary['invalid_rows']);

        $this->get(route('integrations.imports.show', $transfer))
            ->assertSee(self::BANNER)
            ->assertSee('#'.self::SIMPLE_ID)
            ->assertSee('without a valid ID or name');

        $this->post(route('integrations.imports.confirm', $transfer), ['import_new' => '1']);

        $transfer->refresh();
        $this->assertSame(self::VALID_PRODUCTS, $store->links()->count());
        $this->assertSame(self::VALID_PRODUCTS, $transfer->summary['created']);

        $this->get(route('integrations.imports.show', $transfer))->assertSee('Import results')->assertSee('Store ID #'.self::SIMPLE_ID);

        Queue::assertNothingPushed();
    }

    public function test_repeated_imports_dont_create_duplicates(): void
    {
        $store = $this->importedStore();
        $products = Product::query()->where('demo_store_id', $store->id)->count();

        $this->post(route('integrations.imports.store', $store), ['language' => 'en']);
        $transfer = $this->latestTransfer();

        $this->assertSame(0, $transfer->summary['new']);
        $this->assertSame(self::VALID_PRODUCTS, $transfer->summary['linked']);

        $this->post(route('integrations.imports.confirm', $transfer), []);

        $this->assertSame(self::VALID_PRODUCTS, $this->latestTransfer()->summary['skipped']);
        $this->assertSame($products, Product::query()->where('demo_store_id', $store->id)->count());
        $this->assertSame(self::VALID_PRODUCTS, $store->links()->count());
    }

    public function test_demo_products_stay_out_of_the_real_catalog(): void
    {
        $real = Product::factory()->for($this->user)->create(['sku' => 'DEMO-GM-101', 'original_name' => 'My real product']);

        $store = $this->importedStore();

        $this->assertSame(1, $this->user->products()->count());
        $this->assertTrue($this->user->products()->first()->is($real));
        $this->assertSame(0, $store->links()->where('product_id', $real->id)->count());
        $this->assertSame(0, Product::query()->where('demo_store_id', $store->id)->whereNotNull('sku')->count());
        $this->assertSame('DEMO-GM-101', $this->linkFor($store)->remote_sku);

        $this->get(route('products.index'))->assertSee('My real product')->assertDontSee('Aurora RGB Mouse Pad');
        $this->get(route('dashboard'))->assertDontSee('Aurora RGB Mouse Pad');
        $this->get(route('ai-studio'))->assertDontSee('Aurora RGB Mouse Pad');
        $this->get(route('products.show', $this->linkFor($store)->product))->assertOk()->assertSee(self::BANNER)->assertDontSee('data-ai-open', false);
    }

    public function test_real_mappings_are_untouched_when_remote_ids_match(): void
    {
        $realStore = $this->connectedStore($this->user);
        $realLink = $this->linkedProduct($this->user, $realStore, self::SIMPLE_ID);

        $store = $this->importedStore();

        $this->assertNotSame($realLink->product_id, $this->linkFor($store)->product_id);
        $this->assertSame(1, $realStore->links()->count());
        $this->assertTrue($realLink->fresh()->product->is($realLink->product));
    }

    public function test_sample_output_uses_no_gemini_tokens_or_quota(): void
    {
        $store = $this->importedStore();
        $remaining = app(GenerationQuota::class)->remainingFor($this->user);

        $this->post(route('integrations.demo.sample', $store))->assertRedirect(route('ai.reviews.index'))->assertSessionHas('status');

        // The grouped product is read-only, so it gets no draft.
        $this->assertSame(self::VALID_PRODUCTS - 1, $this->user->bulkGenerationItems()->count());
        $this->assertSame($remaining, app(GenerationQuota::class)->remainingFor($this->user));
        $this->assertSame(0, $this->user->aiGenerations()->count());
        $this->assertSame(0, (int) AiGeneration::query()->sum('total_tokens'));

        $this->get(route('ai.reviews.index'))->assertSee('Sample AI Output')->assertSee(self::BANNER);
        $this->get(route('generation-history'))->assertDontSee('Aurora RGB Mouse Pad');
    }

    public function test_sample_output_is_deterministic_and_valid(): void
    {
        $store = $this->importedStore();
        $product = $this->linkFor($store)->product;
        $service = app(WooCommerceDemoContentService::class);

        $sample = $service->sampleFor($product);

        $this->assertSame($sample, $service->sampleFor($product));
        $this->assertTrue(Validator::make(ProductContentRules::normalise($sample, ContentLanguage::English), ProductContentRules::rules())->passes());
    }

    public function test_repeating_sample_generation_doesnt_queue_duplicates(): void
    {
        $store = $this->importedStore();

        $this->post(route('integrations.demo.sample', $store), ['remote_id' => self::SIMPLE_ID]);
        $this->post(route('integrations.demo.sample', $store), ['remote_id' => self::SIMPLE_ID])->assertSessionHasErrors('sample');

        $this->assertSame(1, $this->user->bulkGenerationItems()->count());
    }

    public function test_real_gemini_needs_explicit_opt_in(): void
    {
        $store = $this->importedStore();
        $this->get(route('integrations.demo.product', [$store, self::SIMPLE_ID]))->assertSee('real request to Gemini')->assertSee('Sample AI Output');

        $this->post(route('integrations.demo.real', [$store, self::SIMPLE_ID]), [])->assertSessionHasErrors('confirm_real_ai');

        $this->assertSame(0, AiGeneration::query()->count());
    }

    public function test_the_normal_generate_endpoints_refuse_demo_products(): void
    {
        $store = $this->importedStore();
        $product = $this->linkFor($store)->product;

        $this->postJson(route('ai.generate', $product), ['language' => 'en'])->assertUnprocessable()->assertJsonPath('code', 'demo_product');
        $this->get(route('ai-studio', ['product' => $product->id]))->assertRedirect(route('integrations.demo.show', $store));

        $this->assertSame(0, AiGeneration::query()->count());
    }

    public function test_real_gemini_opt_in_uses_the_existing_generator_and_tracks_tokens(): void
    {
        $store = $this->importedStore();
        $generated = app(WooCommerceDemoContentService::class)->sampleFor($this->linkFor($store)->product);

        $generator = $this->mock(ProductContentGenerator::class);
        $generator->shouldReceive('generate')->once()->andReturnUsing(function (User $user, Product $product) use ($generated) {
            return $user->aiGenerations()->create([
                'product_id' => $product->id,
                'product_name' => $product->original_name,
                'provider' => 'gemini',
                'model' => 'test-model',
                'language' => ContentLanguage::English,
                'status' => AiGenerationStatus::Succeeded,
                'input_tokens' => 10,
                'output_tokens' => 20,
                'total_tokens' => 30,
                'generated_content' => $generated,
            ]);
        });

        $this->post(route('integrations.demo.real', [$store, self::SIMPLE_ID]), ['confirm_real_ai' => '1'])->assertRedirect();

        $item = $this->user->bulkGenerationItems()->sole();
        $this->assertSame(BulkGenerationItem::REVIEW_PENDING, $item->review_status);
        $this->assertSame(30, $this->user->aiGenerations()->sole()->total_tokens);
        $this->assertFalse($item->generation->isSample());
    }

    public function test_review_can_edit_approve_and_label_sample_content(): void
    {
        $store = $this->importedStore();

        $this->post(route('integrations.demo.sample', $store), ['remote_id' => self::SIMPLE_ID]);
        $item = $this->user->bulkGenerationItems()->sole();
        $link = $this->linkFor($store);

        $this->get(route('ai.reviews.edit', $item))->assertOk()->assertSee('Sample AI Output')->assertSee('Reject');

        // Imported content exists, so approval needs the same explicit overwrite as anywhere else.
        $fields = [...$item->reviewableContent(), 'title' => 'Edited sample title', 'keywords' => 'one, two', 'action' => 'approve'];
        $this->put(route('ai.reviews.update', $item), $fields)->assertSessionHasErrors('confirm_overwrite');
        $this->assertSame(ContentSource::WooCommerce, $link->product->contents()->sole()->source);

        $this->put(route('ai.reviews.update', $item), [...$fields, 'confirm_overwrite' => '1'])->assertRedirect(route('ai.reviews.index'));

        $content = $link->product->contents()->sole();
        $this->assertSame('Edited sample title', $content->title);
        $this->assertSame(ContentSource::Sample, $content->source);
        $this->assertTrue($content->source->isApprovedForSync());
        $this->assertSame(BulkGenerationItem::REVIEW_APPROVED, $item->refresh()->review_status);

        $this->get(route('integrations.demo.product', [$store, self::SIMPLE_ID]))->assertSee('Approved')->assertSee('not generated by Gemini');
    }

    public function test_rejecting_leaves_the_product_unchanged_and_allows_a_new_draft(): void
    {
        $store = $this->importedStore();

        $this->post(route('integrations.demo.sample', $store), ['remote_id' => self::SIMPLE_ID]);
        $item = $this->user->bulkGenerationItems()->sole();
        $before = $this->linkFor($store)->product->contents()->sole()->title;

        $this->post(route('ai.reviews.reject', $item))->assertRedirect(route('ai.reviews.index'));

        $this->assertSame(BulkGenerationItem::REVIEW_REJECTED, $item->refresh()->review_status);
        $this->assertSame($before, $this->linkFor($store)->product->contents()->sole()->title);
        $this->get(route('ai.reviews.index', ['status' => 'all']))->assertSee('Rejected');

        $this->post(route('integrations.demo.sample', $store), ['remote_id' => self::SIMPLE_ID])->assertSessionHas('status');
        $this->assertSame(2, $this->user->bulkGenerationItems()->count());
    }

    public function test_real_drafts_cant_be_rejected_through_the_demo_action(): void
    {
        $product = Product::factory()->for($this->user)->create();
        $batch = $this->user->bulkGenerationBatches()->create(['submission_token' => 'a1a1a1a1-0000-4000-8000-000000000000', 'status' => BulkGenerationBatch::COMPLETED, 'preferences' => []]);
        $item = $batch->items()->create(['user_id' => $this->user->id, 'product_id' => $product->id, 'language' => 'en', 'status' => BulkItemStatus::Generated, 'review_status' => BulkGenerationItem::REVIEW_PENDING]);

        $this->actingAs($this->user)->post(route('ai.reviews.reject', $item))->assertNotFound();

        $this->assertSame(BulkGenerationItem::REVIEW_PENDING, $item->refresh()->review_status);
    }

    public function test_preview_compares_against_simulated_values(): void
    {
        $store = $this->importedStore();
        $this->approveSample($store);

        $run = $this->preview($store);
        $item = $run->items()->sole();

        $this->assertSame(WooCommerceSyncRun::READY, $run->status);
        $this->assertSame('Aurora RGB Mouse Pad', $item->fields['name']['remote_display']);
        $this->assertTrue($item->fields['name']['changed']);
        $this->assertFalse($item->fields['meta_title']['supported']);

        $this->get(route('integrations.sync.show', $run))
            ->assertSee(self::BANNER)
            ->assertSee('Simulate Remote Change')
            ->assertSee('Protected fields unchanged')
            ->assertSee('Run simulated synchronization');
    }

    public function test_sync_persists_and_leaves_protected_fields_alone(): void
    {
        $store = $this->importedStore();
        $this->approveSample($store);
        $before = $this->remote($store);

        $run = $this->preview($store);
        $this->confirm($run);

        $after = $this->remote($store);
        $link = $this->linkFor($store);

        $this->assertSame(WooCommerceSyncRun::COMPLETED, $run->refresh()->status);
        $this->assertStringContainsString('for Everyday Gaming Setups', $after['name']);
        $this->assertNotSame($before['description'], $after['description']);
        $this->assertSame($before['slug'], $after['slug']);
        $this->assertNotSame($before['date_modified_gmt'], $after['date_modified_gmt']);

        foreach (['price', 'regular_price', 'sale_price', 'stock_quantity', 'stock_status', 'sku', 'images', 'status', 'variations', 'attributes', 'weight', 'dimensions', 'shipping_class', 'tax_status', 'tax_class', 'meta_data'] as $field) {
            $this->assertSame($before[$field], $after[$field], "{$field} changed.");
        }

        $this->assertNotNull($link->last_synced_at);

        $record = $store->demoProducts()->where('remote_id', self::SIMPLE_ID)->firstOrFail();
        $this->assertTrue(collect(app(WooCommerceDemoService::class)->protectedFields($record))->every('unchanged'));

        $this->get(route('integrations.demo.product', [$store, self::SIMPLE_ID]))
            ->assertSee(self::BANNER)
            ->assertSee('for Everyday Gaming Setups')
            ->assertSee('Unchanged')
            ->assertDontSee('>Changed<', false);
    }

    public function test_variable_products_keep_their_variations_after_sync(): void
    {
        $store = $this->importedStore();
        $this->approveSample($store, 900103);
        $before = $this->remote($store, 900103);

        $this->confirm($this->preview($store, 900103));

        $after = $this->remote($store, 900103);
        $this->assertNotSame($before['name'], $after['name']);
        $this->assertSame($before['variations'], $after['variations']);
        $this->assertSame($before['attributes'], $after['attributes']);
    }

    public function test_read_only_demo_products_are_skipped(): void
    {
        $store = $this->importedStore();

        $this->assertTrue($this->linkFor($store, 900113)->isReadOnly());
        $this->post(route('integrations.demo.sample', $store), ['remote_id' => 900113])->assertSessionHasErrors('sample');
    }

    public function test_more_than_the_inline_limit_still_runs_without_a_worker(): void
    {
        config(['woocommerce.sync_inline_items' => 0, 'woocommerce.import_inline_pages' => 1]);
        Queue::fake([FetchWooCommerceProducts::class, ProcessWooCommerceSyncRun::class]);

        $store = $this->importedStore();
        $this->approveSample($store);

        $this->assertSame(WooCommerceSyncRun::READY, $this->preview($store)->status);
        Queue::assertNothingPushed();
    }

    public function test_simulated_remote_change_is_detected_and_can_be_resolved(): void
    {
        $store = $this->importedStore();
        $this->approveSample($store);

        $run = $this->preview($store);
        $item = $run->items()->sole();
        $original = $this->remote($store)['name'];

        $this->post(route('integrations.demo.simulate-change', $item))->assertSessionHas('status');
        $changed = $this->remote($store)['name'];
        $this->assertNotSame($original, $changed);

        $this->confirm($run);

        $this->assertSame(WooCommerceSyncRun::CONFLICT, $run->refresh()->status);
        $this->assertSame(WooCommerceSyncItem::CONFLICT, $item->refresh()->status);
        $this->assertSame($changed, $this->remote($store)['name']);

        $this->get(route('integrations.sync.show', $run))
            ->assertSee('Original value in store')
            ->assertSee($original)
            ->assertSee('New value in store')
            ->assertSee($changed)
            ->assertSee('Proposed')
            ->assertSee('Refresh preview');

        $this->post(route('integrations.sync.refresh', $run));

        $fresh = $this->user->wooCommerceSyncRuns()->latest('id')->firstOrFail();
        $this->assertNotTrue($fresh->is($run));
        $this->assertSame($changed, $fresh->items()->sole()->fields['name']['remote_display']);

        $this->confirm($fresh);

        $this->assertSame(WooCommerceSyncRun::COMPLETED, $fresh->refresh()->status);
        $this->assertStringContainsString('for Everyday Gaming Setups', $this->remote($store)['name']);
    }

    public function test_remote_change_is_only_available_for_demo_stores(): void
    {
        $real = $this->connectedStore($this->user);
        $link = $this->linkedProduct($this->user, $real);
        $run = $this->user->wooCommerceSyncRuns()->create(['woocommerce_store_id' => $real->id, 'language' => 'en', 'status' => WooCommerceSyncRun::READY]);
        $item = $run->items()->create(['woocommerce_product_link_id' => $link->id, 'product_id' => $link->product_id, 'remote_id' => 101, 'product_name' => 'x', 'language' => 'en', 'status' => WooCommerceSyncItem::READY, 'fields' => []]);

        $this->actingAs($this->user)->post(route('integrations.demo.simulate-change', $item))->assertNotFound();
        $this->get(route('integrations.demo.show', $real))->assertNotFound();
        $this->post(route('integrations.demo.reset', $real), ['confirm' => '1'])->assertNotFound();

        $this->expectException(\LogicException::class);
        app(WooCommerceDemoService::class)->reset($real);
    }

    public function test_history_labels_simulated_runs_with_their_changes(): void
    {
        $store = $this->importedStore();
        $this->approveSample($store);

        $run = $this->preview($store);
        $this->confirm($run);

        $item = $run->items()->sole();
        $this->assertSame(['name', 'description', 'short_description'], array_keys($item->previous_values));
        $this->assertSame('Aurora RGB Mouse Pad', $item->previous_values['name']);
        $this->assertStringContainsString('for Everyday Gaming Setups', $item->applied_values['name']);

        $this->get(route('integrations.sync.history'))
            ->assertSee(self::BANNER)
            ->assertSee('Simulation')
            ->assertSee('Demo WooCommerce Store')
            ->assertSee('Aurora RGB Mouse Pad: Product title, Long description, Short description');

        $this->get(route('integrations.sync.show', $run))->assertSee('Previous value')->assertSee('Updated value');
    }

    public function test_rollback_restores_the_simulated_product(): void
    {
        $store = $this->importedStore();
        $this->approveSample($store);
        $original = $this->remote($store)['name'];

        $run = $this->preview($store);
        $this->confirm($run);

        $this->post(route('integrations.sync.rollback', $run));
        $rollback = $this->user->wooCommerceSyncRuns()->latest('id')->firstOrFail();
        $this->confirm($rollback, ['name']);

        $this->assertSame($original, $this->remote($store)['name']);
    }

    public function test_real_store_history_has_no_simulation_label(): void
    {
        $real = $this->connectedStore($this->user);
        $this->user->wooCommerceSyncRuns()->create(['woocommerce_store_id' => $real->id, 'language' => 'en', 'status' => WooCommerceSyncRun::COMPLETED]);

        $this->actingAs($this->user)->get(route('integrations.sync.history'))->assertDontSee(self::BANNER)->assertDontSee('Simulation');
    }

    public function test_reset_requires_confirmation(): void
    {
        $store = $this->importedStore();

        $this->post(route('integrations.demo.reset', $store))->assertSessionHasErrors('confirm');

        $this->assertSame(self::VALID_PRODUCTS, $store->links()->count());
    }

    public function test_reset_restores_the_catalog_and_removes_only_demo_data(): void
    {
        $realProduct = Product::factory()->for($this->user)->create();
        $realStore = $this->connectedStore($this->user);
        $realLink = $this->linkedProduct($this->user, $realStore);
        $realGeneration = AiGeneration::factory()->for($this->user)->create(['product_id' => $realProduct->id]);
        $realTransfer = $this->user->catalogTransfers()->create(['operation' => CatalogTransfer::EXPORT, 'file_type' => 'csv', 'status' => 'completed', 'options' => [], 'summary' => []]);
        $realBatch = $this->user->bulkGenerationBatches()->create(['submission_token' => 'b2b2b2b2-0000-4000-8000-000000000000', 'status' => BulkGenerationBatch::COMPLETED, 'preferences' => []]);
        $realItem = $realBatch->items()->create(['user_id' => $this->user->id, 'product_id' => $realProduct->id, 'language' => 'en', 'status' => BulkItemStatus::Generated, 'review_status' => BulkGenerationItem::REVIEW_PENDING]);

        $store = $this->importedStore();
        $this->approveSample($store);
        $this->confirm($this->preview($store));
        $this->post(route('integrations.demo.outage', $store), ['outage' => 1]);

        $this->assertNotSame(DemoCatalog::find(self::SIMPLE_ID), $this->remote($store));

        $this->post(route('integrations.demo.reset', $store), ['confirm' => '1'])->assertRedirect(route('integrations.demo.show', $store));

        $store->refresh();
        $this->assertSame(DemoCatalog::find(self::SIMPLE_ID), $this->remote($store));
        $this->assertSame(count(DemoCatalog::products()), $store->demoProducts()->count());
        $this->assertSame(0, $store->links()->count());
        $this->assertSame(0, $store->syncRuns()->count());
        $this->assertSame(0, Product::query()->where('demo_store_id', $store->id)->count());
        $this->assertSame(0, AiGeneration::query()->where('provider', AiGeneration::SAMPLE_PROVIDER)->count());
        $this->assertFalse($store->simulated_outage);
        $this->assertNull($store->last_import_at);
        $this->assertNull($store->last_sync_at);
        $this->assertSame(1, $this->user->bulkGenerationBatches()->count());
        $this->assertSame(1, $this->user->catalogTransfers()->count());

        $this->assertTrue($realProduct->fresh()->is($realProduct));
        $this->assertNotNull($realLink->fresh());
        $this->assertNotNull($realStore->fresh());
        $this->assertNotNull($realGeneration->fresh());
        $this->assertNotNull($realTransfer->fresh());
        $this->assertNotNull($realBatch->fresh());
        $this->assertNotNull($realItem->fresh());
        $this->assertSame('ck_test_key', $realStore->fresh()->consumer_key);
    }

    public function test_real_gemini_history_survives_a_reset(): void
    {
        $store = $this->importedStore();
        $product = $this->linkFor($store)->product;
        $generation = $this->user->aiGenerations()->create(['product_id' => $product->id, 'product_name' => $product->original_name, 'provider' => 'gemini', 'model' => 'm', 'language' => 'en', 'status' => AiGenerationStatus::Succeeded, 'total_tokens' => 40]);
        $remaining = app(GenerationQuota::class)->remainingFor($this->user);

        $this->post(route('integrations.demo.reset', $store), ['confirm' => '1']);

        $this->assertNull($generation->fresh()->product_id);
        $this->assertSame(40, $generation->fresh()->total_tokens);
        $this->assertSame($remaining, app(GenerationQuota::class)->remainingFor($this->user));
    }

    public function test_the_demo_works_again_after_a_reset(): void
    {
        $store = $this->importedStore();
        $this->post(route('integrations.demo.reset', $store), ['confirm' => '1']);

        $this->post(route('integrations.imports.store', $store), ['language' => 'en']);
        $transfer = $this->latestTransfer();

        $this->assertSame(self::VALID_PRODUCTS, $transfer->summary['new']);
    }

    public function test_one_users_reset_doesnt_affect_another_users_demo(): void
    {
        $other = User::factory()->create();
        $theirs = $this->importedStore($other);
        $mine = $this->importedStore();

        $this->post(route('integrations.demo.reset', $mine), ['confirm' => '1']);

        $this->assertSame(self::VALID_PRODUCTS, $theirs->links()->count());
        $this->assertSame(self::VALID_PRODUCTS, Product::query()->where('demo_store_id', $theirs->id)->count());
    }

    public function test_demo_stores_belong_to_their_owner(): void
    {
        $owner = User::factory()->create();
        $store = $this->importedStore($owner);
        $run = $owner->wooCommerceSyncRuns()->create(['woocommerce_store_id' => $store->id, 'language' => 'en', 'status' => WooCommerceSyncRun::READY]);
        $link = $this->linkFor($store);
        $item = $run->items()->create(['woocommerce_product_link_id' => $link->id, 'product_id' => $link->product_id, 'remote_id' => self::SIMPLE_ID, 'product_name' => 'x', 'language' => 'en', 'status' => WooCommerceSyncItem::READY, 'fields' => []]);
        $before = $this->remote($store);

        $this->actingAs($this->user);

        $this->get(route('integrations.demo.show', $store))->assertForbidden();
        $this->get(route('integrations.demo.product', [$store, self::SIMPLE_ID]))->assertForbidden();
        $this->get(route('integrations.products', $store))->assertForbidden();
        $this->post(route('integrations.demo.test', $store))->assertForbidden();
        $this->post(route('integrations.demo.outage', $store), ['outage' => 1])->assertForbidden();
        $this->post(route('integrations.demo.reset', $store), ['confirm' => '1'])->assertForbidden();
        $this->post(route('integrations.demo.sample', $store))->assertForbidden();
        $this->post(route('integrations.demo.real', [$store, self::SIMPLE_ID]), ['confirm_real_ai' => '1'])->assertForbidden();
        $this->post(route('integrations.demo.simulate-change', $item))->assertForbidden();
        $this->get(route('integrations.sync.show', $run))->assertForbidden();

        $this->assertSame($before, $this->remote($store));
        $this->assertFalse($store->refresh()->simulated_outage);
        $this->assertSame(0, BulkGenerationItem::query()->count());
    }

    public function test_guests_are_redirected(): void
    {
        $this->post(route('integrations.demo.activate'))->assertRedirect(route('login'));
    }

    public function test_demo_records_are_scoped_to_their_store(): void
    {
        $mine = $this->demoStore();
        $theirs = $this->demoStore(User::factory()->create());

        app(WooCommerceService::class)->updateProduct($mine, self::SIMPLE_ID, ['name' => 'Only mine']);

        $this->assertSame('Only mine', $this->remote($mine)['name']);
        $this->assertSame('Aurora RGB Mouse Pad', $this->remote($theirs)['name']);
        $this->assertSame(2 * count(DemoCatalog::products()), WooCommerceDemoProduct::query()->count());
    }
}
