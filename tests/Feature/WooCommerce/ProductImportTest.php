<?php

namespace Tests\Feature\WooCommerce;

use App\Enums\CatalogTransferStatus;
use App\Enums\ContentLanguage;
use App\Enums\ContentSource;
use App\Jobs\ApplyWooCommerceImport;
use App\Jobs\FetchWooCommerceProducts;
use App\Models\CatalogImportRow;
use App\Models\CatalogTransfer;
use App\Models\Product;
use App\Models\ProductContent;
use App\Models\User;
use App\Models\WooCommerceProductLink;
use App\Models\WooCommerceStore;
use App\Services\ProductService;
use App\Services\WooCommerce\WooCommerceProductImporter;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakesGemini;
use Tests\Concerns\FakesWooCommerce;
use Tests\TestCase;

class ProductImportTest extends TestCase
{
    use FakesGemini, FakesWooCommerce, RefreshDatabase;

    private User $user;

    private WooCommerceStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeDns();
        $this->user = User::factory()->create();
        $this->store = $this->connectedStore($this->user);
        $this->actingAs($this->user);
    }

    private function read(?WooCommerceStore $store = null): CatalogTransfer
    {
        $this->post(route('integrations.imports.store', $store ?? $this->store), ['language' => 'en'])->assertRedirect();

        return CatalogTransfer::query()->latest('id')->firstOrFail();
    }

    private function confirm(CatalogTransfer $transfer, array $data = ['import_new' => '1']): CatalogTransfer
    {
        $this->post(route('integrations.imports.confirm', $transfer), $data)->assertSessionHasNoErrors();

        return $transfer->refresh();
    }

    private function import(array $products): CatalogTransfer
    {
        $this->fakeLiveStore($products);

        return $this->confirm($this->read());
    }

    public function test_reading_shows_a_review_and_creates_nothing_locally(): void
    {
        $this->fakeLiveStore([$this->remoteProduct()]);

        $transfer = $this->read();

        $this->assertSame(CatalogTransferStatus::Validated, $transfer->status);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('woocommerce_product_links', 0);
        $this->get(route('integrations.imports.show', $transfer))->assertOk()->assertSee(['New products', 'Remote Product 101', 'Confirm import']);
    }

    public function test_an_import_is_read_only_for_the_store(): void
    {
        $this->import([$this->remoteProduct()]);

        $this->assertCount(0, $this->sentWrites());
    }

    public function test_products_are_mapped_and_linked_by_store(): void
    {
        $transfer = $this->import([$this->remoteProduct(101, ['name' => 'Tote &amp; Bag', 'description' => '<p>Line one.</p><p>Line <b>two</b>.</p>', 'categories' => [['name' => 'Bags'], ['name' => 'Leather']]])]);

        $product = Product::sole();
        $link = WooCommerceProductLink::sole();

        $this->assertSame($this->user->id, $product->user_id);
        $this->assertSame('Tote & Bag', $product->original_name);
        $this->assertSame('SKU-101', $product->sku);
        $this->assertSame('Bags, Leather', $product->category);
        $this->assertSame("Line one.\n\nLine two.", $product->details);
        $this->assertSame('published', $product->status->value);

        $this->assertSame(101, $link->remote_id);
        $this->assertSame($this->store->id, $link->woocommerce_store_id);
        $this->assertSame('SKU-101', $link->remote_sku);
        $this->assertSame('2026-11-01T10:00:00', $link->remote_modified_gmt);
        $this->assertSame('simple', $link->remote_type);
        $this->assertNotNull($link->last_imported_at);
        $this->assertNotNull($this->store->fresh()->last_import_at);
        $this->assertSame(1, $transfer->summary['created']);
    }

    public function test_sensitive_and_commercial_fields_are_not_stored(): void
    {
        $this->import([$this->remoteProduct()]);

        $payload = json_encode(CatalogImportRow::sole()->payload).json_encode(WooCommerceProductLink::sole()->getAttributes());

        $this->assertStringNotContainsString('19.99', $payload);
        $this->assertStringNotContainsString('a.jpg', $payload);
        $this->assertStringNotContainsString('_private', $payload);
        $this->assertStringNotContainsString('stock_quantity', $payload);
    }

    public function test_the_external_reference_keeps_platform_ids_separate(): void
    {
        $this->import([$this->remoteProduct()]);

        $reference = Product::sole()->externalReferences()->sole();

        $this->assertSame('woocommerce-'.$this->store->id, $reference->source);
        $this->assertSame('101', $reference->external_id);
    }

    public function test_imported_text_is_saved_in_the_chosen_language_as_unapproved(): void
    {
        $this->import([$this->remoteProduct()]);

        $content = ProductContent::sole();

        $this->assertSame(ContentLanguage::English, $content->language);
        $this->assertSame(ContentSource::WooCommerce, $content->source);
        $this->assertSame('Old long description.', $content->long_description);
        $this->assertFalse($content->source->isApprovedForSync());
    }

    public function test_pagination_reads_every_page(): void
    {
        Http::fake(function (Request $request) {
            $page = (int) $request->data()['page'];

            return Http::response([$this->remoteProduct(100 + $page)], 200, ['X-WP-TotalPages' => 2]);
        });

        $transfer = $this->read();

        $this->assertSame(2, $transfer->fresh()->summary['new']);
        Http::assertSent(fn (Request $request) => $request->data()['per_page'] === 50 && $request->data()['orderby'] === 'id');
    }

    public function test_an_empty_store_finishes_cleanly(): void
    {
        $this->fakeLiveStore([]);

        $this->assertSame(0, $this->read()->fresh()->summary['products']);
    }

    public function test_larger_stores_continue_on_the_queue(): void
    {
        config(['woocommerce.import_inline_pages' => 1]);
        Queue::fake();
        Http::fake(['*' => Http::response([$this->remoteProduct()], 200, ['X-WP-TotalPages' => 3])]);

        $transfer = $this->read();

        Queue::assertPushed(FetchWooCommerceProducts::class, fn ($job) => $job->transferId === $transfer->id);
        $this->assertSame(2, $transfer->fresh()->options['next_page']);
        $this->get(route('integrations.imports.show', $transfer))->assertSee('queue worker');
    }

    public function test_large_confirmed_imports_go_to_the_queue(): void
    {
        config(['woocommerce.import_inline_rows' => 0]);
        Queue::fake();
        $this->fakeLiveStore([$this->remoteProduct()]);

        $transfer = $this->confirm($this->read());

        Queue::assertPushed(ApplyWooCommerceImport::class);
        $this->assertDatabaseCount('products', 0);
        $this->assertSame(CatalogTransferStatus::Queued, $transfer->status);
    }

    public function test_repeated_imports_do_not_create_duplicates(): void
    {
        $this->import([$this->remoteProduct()]);

        $second = $this->import([$this->remoteProduct()]);

        $this->assertSame(1, Product::count());
        $this->assertSame(1, WooCommerceProductLink::count());
        $this->assertSame(1, $second->summary['skipped']);
    }

    public function test_a_duplicate_id_across_pages_is_staged_once(): void
    {
        Http::fake(['*' => Http::response([$this->remoteProduct()], 200, ['X-WP-TotalPages' => 2])]);

        $this->assertSame(1, $this->read()->fresh()->summary['products']);
    }

    public function test_a_second_read_of_the_same_page_is_harmless(): void
    {
        $this->fakeLiveStore([$this->remoteProduct()]);

        $importer = app(WooCommerceProductImporter::class);
        $transfer = $importer->start($this->store, ContentLanguage::English);

        $importer->fetchNextPage($transfer);
        $transfer->update(['options' => [...$transfer->options, 'next_page' => 1]]);
        $importer->fetchNextPage($transfer->refresh());

        $this->assertSame(1, $transfer->rows()->count());
    }

    public function test_a_repeated_apply_creates_no_duplicates(): void
    {
        $this->fakeLiveStore([$this->remoteProduct()]);
        $transfer = $this->read();

        $importer = app(WooCommerceProductImporter::class);
        $importer->confirm($transfer, [], ['import_new' => true, 'refresh_linked' => false, 'refresh_fields' => []]);

        $importer->apply($transfer->refresh());
        $importer->apply($transfer->refresh());

        $this->assertSame(1, Product::count());
    }

    public function test_a_second_import_cannot_start_while_one_is_running(): void
    {
        config(['woocommerce.import_inline_pages' => 1]);
        Queue::fake();
        Http::fake(['*' => Http::response([$this->remoteProduct()], 200, ['X-WP-TotalPages' => 3])]);

        $first = $this->read();
        $second = $this->read();

        $this->assertTrue($first->is($second));
    }

    public function test_a_stalled_import_does_not_block_the_store_forever(): void
    {
        config(['woocommerce.import_inline_pages' => 1]);
        Queue::fake();
        Http::fake(['*' => Http::response([$this->remoteProduct()], 200, ['X-WP-TotalPages' => 3])]);

        $first = $this->read();
        CatalogTransfer::query()->whereKey($first->id)->update(['updated_at' => now()->subHour()]);

        $this->assertFalse($first->is($this->read()));
    }

    public function test_a_new_read_replaces_a_review_still_waiting(): void
    {
        $this->fakeLiveStore([$this->remoteProduct()]);

        $first = $this->read();
        $second = $this->read();

        $this->assertFalse($first->is($second));
        $this->assertSame(CatalogTransferStatus::Failed, $first->fresh()->status);

        $this->post(route('integrations.imports.confirm', $first), ['import_new' => '1']);

        $this->assertDatabaseCount('products', 0);
    }

    public function test_confirming_twice_applies_once(): void
    {
        $this->fakeLiveStore([$this->remoteProduct()]);
        $transfer = $this->read();

        $this->confirm($transfer);
        $this->confirm($transfer);

        $this->assertSame(1, Product::count());
    }

    public function test_a_failed_read_can_continue_from_where_it_stopped(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push([$this->remoteProduct(1)], 200, ['X-WP-TotalPages' => 2])
            ->push(['code' => 'x'], 400)
            ->push([$this->remoteProduct(2)], 200, ['X-WP-TotalPages' => 2])]);

        $transfer = $this->read();

        $this->assertSame(CatalogTransferStatus::Failed, $transfer->status);

        $this->post(route('integrations.imports.retry', $transfer))->assertRedirect();

        $this->assertSame(2, $transfer->fresh()->summary['products']);
    }

    public function test_a_row_without_a_name_is_reported_and_others_continue(): void
    {
        $this->fakeLiveStore([$this->remoteProduct(1, ['name' => '']), $this->remoteProduct(2)]);

        $transfer = $this->confirm($this->read());

        $this->assertSame(1, Product::count());
        $this->assertSame(1, $transfer->failed_records);
    }

    public function test_one_failing_product_does_not_stop_the_others(): void
    {
        $service = $this->partialMock(ProductService::class);
        $service->shouldReceive('create')->withArgs(fn ($user, $attributes) => $attributes['original_name'] === 'Remote Product 1')->andThrow(new Exception('secret detail'));

        $transfer = $this->import([$this->remoteProduct(1), $this->remoteProduct(2)]);

        $this->assertSame(CatalogTransferStatus::CompletedWithErrors, $transfer->status);
        $this->assertSame(1, WooCommerceProductLink::count());
        $this->get(route('integrations.imports.show', $transfer))->assertDontSee('secret detail');
    }

    public function test_a_local_product_with_the_same_sku_is_never_merged_by_default(): void
    {
        $local = Product::factory()->for($this->user)->create(['sku' => 'SKU-101', 'original_name' => 'My Local']);

        $this->fakeLiveStore([$this->remoteProduct()]);
        $transfer = $this->read();

        $this->assertSame(1, $transfer->summary['possible_duplicates']);
        $this->get(route('integrations.imports.show', $transfer))->assertSee(['Possible matches', 'My Local']);

        $this->confirm($transfer);

        $this->assertSame('My Local', $local->fresh()->original_name);
        $this->assertDatabaseCount('woocommerce_product_links', 0);
    }

    public function test_an_approved_match_links_without_changing_local_data(): void
    {
        $local = Product::factory()->for($this->user)->create(['sku' => 'SKU-101', 'original_name' => 'My Local']);
        $this->fakeLiveStore([$this->remoteProduct()]);
        $transfer = $this->read();

        $this->confirm($transfer, ['decisions' => [$transfer->rows()->sole()->id => 'link']]);

        $this->assertSame($local->id, WooCommerceProductLink::sole()->product_id);
        $this->assertSame('My Local', $local->fresh()->original_name);
        $this->assertDatabaseCount('product_contents', 0);
    }

    public function test_a_match_can_be_imported_as_separate_keeping_the_original_sku_on_the_link(): void
    {
        $local = Product::factory()->for($this->user)->create(['sku' => 'SKU-101']);
        $this->fakeLiveStore([$this->remoteProduct()]);
        $transfer = $this->read();

        $this->confirm($transfer, ['decisions' => [$transfer->rows()->sole()->id => 'new']]);

        $this->assertSame(2, Product::count());
        $this->assertNull(WooCommerceProductLink::sole()->product->sku);
        $this->assertSame('SKU-101', WooCommerceProductLink::sole()->remote_sku);
        $this->assertSame('SKU-101', $local->fresh()->sku);
    }

    public function test_two_remote_products_with_one_sku_are_ambiguous_and_cannot_be_linked(): void
    {
        $local = Product::factory()->for($this->user)->create(['sku' => 'DUP']);
        $this->fakeLiveStore([$this->remoteProduct(1, ['sku' => 'DUP']), $this->remoteProduct(2, ['sku' => 'DUP'])]);
        $transfer = $this->read();

        $this->assertSame(2, $transfer->rows()->where('match_type', WooCommerceProductImporter::AMBIGUOUS)->count());

        $this->confirm($transfer, ['decisions' => $transfer->rows()->pluck('id')->mapWithKeys(fn ($id) => [$id => 'link'])->all()]);

        $this->assertDatabaseCount('woocommerce_product_links', 0);
        $this->assertSame(1, Product::where('id', $local->id)->count());
    }

    public function test_a_local_product_linked_to_another_remote_product_is_not_offered(): void
    {
        $link = $this->linkedProduct($this->user, $this->store, 999);
        $link->product->update(['sku' => 'SKU-101']);

        $this->fakeLiveStore([$this->remoteProduct()]);

        $this->assertSame(WooCommerceProductImporter::AMBIGUOUS, $this->read()->rows()->sole()->match_type);
    }

    public function test_the_same_id_and_sku_can_exist_in_different_stores(): void
    {
        $other = $this->connectedStore($this->user, ['url' => 'https://other.example.com', 'name' => 'Other']);

        $this->import([$this->remoteProduct()]);

        $this->fakeLiveStore([$this->remoteProduct()]);
        $transfer = $this->read($other);
        $this->confirm($transfer, ['decisions' => [$transfer->rows()->sole()->id => 'new']]);

        $this->assertSame(2, WooCommerceProductLink::where('remote_id', 101)->count());
        $this->assertSame(2, Product::count());
    }

    public function test_another_users_sku_is_never_matched(): void
    {
        Product::factory()->create(['sku' => 'SKU-101', 'original_name' => 'Foreign']);
        $this->fakeLiveStore([$this->remoteProduct()]);

        $this->assertNull($this->read()->rows()->sole()->match_type);
    }

    public function test_product_types_get_the_right_sync_mode(): void
    {
        $this->import([
            $this->remoteProduct(1, ['type' => 'simple']),
            $this->remoteProduct(2, ['type' => 'variable', 'variations' => [10, 11]]),
            $this->remoteProduct(3, ['type' => 'grouped']),
            $this->remoteProduct(4, ['type' => 'external']),
            $this->remoteProduct(5, ['type' => 'subscription']),
        ]);

        $modes = WooCommerceProductLink::orderBy('remote_id')->pluck('sync_mode')->all();

        $this->assertSame(['full', 'descriptive', 'read_only', 'read_only', 'read_only'], $modes);
        $this->assertSame(2, WooCommerceProductLink::where('remote_id', 2)->sole()->metadata['variation_count']);
    }

    public function test_the_review_shows_what_a_refresh_would_change(): void
    {
        $this->import([$this->remoteProduct()]);

        $this->fakeLiveStore([$this->remoteProduct(101, ['name' => 'Renamed in store'])]);
        $transfer = $this->read();

        $this->assertSame(['Product name'], $transfer->rows()->sole()->payload['changes']);
        $this->get(route('integrations.imports.show', $transfer))->assertSee('may change Product name');
    }

    public function test_refreshing_requires_confirmation_and_chosen_fields(): void
    {
        $this->import([$this->remoteProduct()]);
        $this->fakeLiveStore([$this->remoteProduct()]);
        $transfer = $this->read();

        $this->post(route('integrations.imports.confirm', $transfer), ['refresh_linked' => '1'])
            ->assertSessionHasErrors(['refresh_fields', 'confirm_refresh']);
    }

    public function test_refreshing_changes_only_selected_fields(): void
    {
        $this->import([$this->remoteProduct()]);

        $this->fakeLiveStore([$this->remoteProduct(101, ['name' => 'Renamed', 'categories' => [['name' => 'Changed']]])]);

        $this->confirm($this->read(), ['refresh_linked' => '1', 'refresh_fields' => ['category'], 'confirm_refresh' => '1']);

        $product = Product::sole();

        $this->assertSame('Remote Product 101', $product->original_name);
        $this->assertSame('Changed', $product->category);
    }

    public function test_refreshing_never_overwrites_generated_or_edited_content(): void
    {
        $this->import([$this->remoteProduct()]);
        ProductContent::sole()->update(['title' => 'AI title', 'source' => ContentSource::Ai]);

        $this->fakeLiveStore([$this->remoteProduct(101, ['name' => 'Store title'])]);

        $this->confirm($this->read(), ['refresh_linked' => '1', 'refresh_fields' => ['imported_content'], 'confirm_refresh' => '1']);

        $this->assertSame('AI title', ProductContent::sole()->title);
    }

    public function test_refreshing_can_update_content_still_as_imported(): void
    {
        $this->import([$this->remoteProduct()]);

        $this->fakeLiveStore([$this->remoteProduct(101, ['name' => 'Store title'])]);

        $this->confirm($this->read(), ['refresh_linked' => '1', 'refresh_fields' => ['imported_content'], 'confirm_refresh' => '1']);

        $this->assertSame('Store title', ProductContent::sole()->title);
    }

    public function test_wpml_translation_ids_are_recorded(): void
    {
        $this->import([$this->remoteProduct(101, ['lang' => 'en', 'translations' => ['de' => 202, 'sq' => 303, 'bad' => 'x']])]);

        $link = WooCommerceProductLink::sole();

        $this->assertSame('en', $link->remote_language);
        $this->assertSame(['de' => 202, 'sq' => 303], $link->translations);
    }

    public function test_imported_products_can_use_existing_ai_generation(): void
    {
        $this->import([$this->remoteProduct()]);
        $this->geminiReply = $this->geminiBody();

        $this->postJson(route('ai.generate', Product::sole()), ['language' => 'de'])
            ->assertCreated()
            ->assertJsonPath('content.title', 'Leather Tote Bag');
    }

    public function test_imported_content_is_protected_by_the_overwrite_check(): void
    {
        $this->import([$this->remoteProduct()]);
        $this->geminiReply = $this->geminiBody();

        $response = $this->postJson(route('ai.generate', Product::sole()), ['language' => 'en'])->assertCreated();

        $this->postJson($response->json('save_url'), $this->generatedFields())->assertConflict();
    }

    public function test_store_imports_appear_in_transfer_history(): void
    {
        $this->import([$this->remoteProduct()]);

        $this->get(route('catalog.history'))->assertOk()->assertSee(route('integrations.imports.show', CatalogTransfer::sole()), false);
    }

    public function test_file_import_pages_refuse_store_imports(): void
    {
        $this->fakeLiveStore([$this->remoteProduct()]);
        $transfer = $this->read();

        $this->get(route('catalog.imports.mapping', $transfer))->assertNotFound();
    }

    public function test_another_users_import_is_inaccessible(): void
    {
        $this->fakeLiveStore([$this->remoteProduct()]);
        $transfer = $this->read();
        $this->actingAs(User::factory()->create());

        $this->get(route('integrations.imports.show', $transfer))->assertForbidden();
        $this->post(route('integrations.imports.confirm', $transfer), ['import_new' => '1'])->assertForbidden();
        $this->post(route('integrations.imports.retry', $transfer))->assertForbidden();
    }
}
