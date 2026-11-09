<?php

namespace Tests\Feature\WooCommerce;

use App\Enums\ContentLanguage;
use App\Enums\ContentSource;
use App\Enums\WooCommerceError;
use App\Jobs\ProcessWooCommerceSyncRun;
use App\Models\ProductContent;
use App\Models\User;
use App\Models\WooCommerceProductLink;
use App\Models\WooCommerceStore;
use App\Models\WooCommerceSyncItem;
use App\Models\WooCommerceSyncRun;
use App\Services\WooCommerce\Exceptions\WooCommerceException;
use App\Services\WooCommerce\WooCommerceConnectionService;
use App\Services\WooCommerce\WooCommerceService;
use App\Services\WooCommerce\WooCommerceSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakesWooCommerce;
use Tests\TestCase;

class ProductSyncTest extends TestCase
{
    use FakesWooCommerce, RefreshDatabase;

    private const ALL_FIELDS = ['name', 'description', 'short_description'];

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

    private function preview(array $links, string $language = 'en', ?WooCommerceStore $store = null): WooCommerceSyncRun
    {
        $this->post(route('integrations.sync.store', $store ?? $this->store), [
            'language' => $language,
            'link_ids' => array_map(fn ($link) => $link->id, $links),
        ])->assertRedirect();

        return WooCommerceSyncRun::query()->latest('id')->firstOrFail();
    }

    /**
     * @param  list<string>  $fields
     */
    private function confirm(WooCommerceSyncRun $run, array $fields = self::ALL_FIELDS): void
    {
        $selected = $run->items()->where('status', WooCommerceSyncItem::READY)->pluck('id')
            ->mapWithKeys(fn ($id) => [$id => $fields])->all();

        $this->post(route('integrations.sync.confirm', $run), ['selected' => $selected, 'confirm' => '1'])->assertRedirect();
    }

    /**
     * @return array{WooCommerceProductLink, WooCommerceSyncRun}
     */
    private function readyPreview(array $contentAttributes = [], array $remoteOverrides = []): array
    {
        $this->fakeLiveStore([$this->remoteProduct(101, $remoteOverrides)]);
        $link = $this->linkedProduct($this->user, $this->store);
        $this->approvedContent($link, $contentAttributes);

        return [$link, $this->preview([$link])];
    }

    public function test_the_linked_products_page_lists_links_and_approved_languages(): void
    {
        $link = $this->linkedProduct($this->user, $this->store);
        $this->approvedContent($link);

        $this->get(route('integrations.products', $this->store))
            ->assertOk()
            ->assertSee([$link->product->original_name, '#101', 'Full content', 'Sync Content']);
    }

    public function test_the_language_page_only_offers_the_stores_language(): void
    {
        $link = $this->linkedProduct($this->user, $this->store);

        $response = $this->get(route('integrations.sync.create', ['store' => $this->store, 'link_ids' => [$link->id]]))->assertOk();

        $this->assertSame([ContentLanguage::English], $response->viewData('languages'));
    }

    public function test_a_preview_never_writes_and_shows_field_level_changes(): void
    {
        [$link, $run] = $this->readyPreview();

        $this->assertSame(WooCommerceSyncRun::READY, $run->status);
        $this->assertCount(0, $this->sentWrites());

        $this->get(route('integrations.sync.show', $run))
            ->assertOk()
            ->assertSee([
                $link->product->original_name,
                'Local #'.$link->product_id,
                'WooCommerce #101',
                'Product title',
                'Remote Product 101',
                'New Title',
                'Old long description.',
                'New long description.',
                'Confirm synchronization',
            ]);
    }

    public function test_the_preview_escapes_store_and_ai_content(): void
    {
        [, $run] = $this->readyPreview(['title' => '<img src=x onerror=alert(1)>'], ['name' => '<script>alert(2)</script>Old']);

        $html = $this->get(route('integrations.sync.show', $run))->getContent();

        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<script>alert(2)', $html);
    }

    public function test_slug_is_not_selected_by_default_and_price_is_never_offered(): void
    {
        [, $run] = $this->readyPreview();

        $html = $this->get(route('integrations.sync.show', $run))->getContent();

        $this->assertMatchesRegularExpression('/value="name"\s+checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/value="slug"\s+checked/', $html);
        $this->assertStringNotContainsString('value="price"', $html);
    }

    public function test_unapproved_imported_content_cannot_be_synchronized(): void
    {
        $this->fakeLiveStore([$this->remoteProduct()]);
        $link = $this->linkedProduct($this->user, $this->store);
        $this->approvedContent($link, ['source' => ContentSource::WooCommerce]);

        $run = $this->preview([$link]);

        $this->assertSame(WooCommerceSyncRun::SKIPPED, $run->status);
        $this->assertStringContainsString('no approved', $run->items()->sole()->message);
    }

    public function test_pending_bulk_drafts_are_not_used(): void
    {
        $this->fakeLiveStore([$this->remoteProduct()]);
        $link = $this->linkedProduct($this->user, $this->store);

        $this->assertSame(WooCommerceSyncRun::SKIPPED, $this->preview([$link])->status);
    }

    public function test_manually_written_content_counts_as_approved(): void
    {
        $this->fakeLiveStore([$this->remoteProduct()]);
        $link = $this->linkedProduct($this->user, $this->store);
        $this->approvedContent($link, ['source' => ContentSource::Manual]);

        $this->assertSame(WooCommerceSyncRun::READY, $this->preview([$link])->status);
    }

    public function test_only_selected_allowlisted_fields_are_sent(): void
    {
        [, $run] = $this->readyPreview();

        $this->confirm($run, ['name', 'description']);

        $write = $this->sentWrites()->sole();

        $this->assertSame('PUT', $write->method());
        $this->assertStringEndsWith('/wp-json/wc/v3/products/101', $write->url());
        $this->assertSame(['name' => 'New Title', 'description' => '<p>New long description.</p>'], $write->data());
    }

    public function test_protected_fields_remain_unchanged_in_the_store(): void
    {
        [, $run] = $this->readyPreview();
        $before = $this->remoteProducts[101];

        $this->confirm($run);

        $after = $this->remoteProducts[101];

        foreach (['price', 'regular_price', 'sale_price', 'stock_quantity', 'images', 'sku', 'status', 'attributes', 'variations', 'categories', 'meta_data', 'type'] as $field) {
            $this->assertSame($before[$field], $after[$field], "{$field} changed");
        }
    }

    public function test_tampered_requests_cannot_send_protected_fields(): void
    {
        [, $run] = $this->readyPreview();
        $item = $run->items()->sole();

        $this->post(route('integrations.sync.confirm', $run), [
            'selected' => [$item->id => ['name', 'price', 'sku', 'status', 'stock_quantity']],
            'confirm' => '1',
        ]);

        $this->assertSame(['name'], array_keys($this->sentWrites()->sole()->data()));
    }

    public function test_the_service_itself_refuses_non_allowlisted_fields(): void
    {
        $this->fakeLiveStore([$this->remoteProduct()]);

        app(WooCommerceService::class)->updateProduct($this->store, 101, ['name' => 'X', 'price' => '1', 'status' => 'draft']);

        $this->assertSame(['name'], array_keys($this->sentWrites()->sole()->data()));
    }

    public function test_the_service_sends_nothing_when_no_allowed_field_remains(): void
    {
        $this->fakeLiveStore([$this->remoteProduct()]);

        $this->expectException(WooCommerceException::class);

        try {
            app(WooCommerceService::class)->updateProduct($this->store, 101, ['price' => '1']);
        } finally {
            $this->assertCount(0, $this->sentWrites());
        }
    }

    public function test_the_write_is_never_followed_through_a_redirect_or_query_secret(): void
    {
        [, $run] = $this->readyPreview();

        $this->confirm($run);

        Http::assertSent(fn (Request $request) => ! str_contains($request->url(), 'consumer_'));
    }

    public function test_confirmation_is_required(): void
    {
        [, $run] = $this->readyPreview();

        $this->post(route('integrations.sync.confirm', $run), ['selected' => [$run->items()->sole()->id => ['name']]])
            ->assertSessionHasErrors('confirm');

        $this->assertCount(0, $this->sentWrites());
    }

    public function test_a_selection_is_required(): void
    {
        [, $run] = $this->readyPreview();

        $this->post(route('integrations.sync.confirm', $run), ['confirm' => '1'])->assertSessionHasErrors('selected');

        $this->assertCount(0, $this->sentWrites());
    }

    public function test_confirming_twice_writes_once(): void
    {
        [, $run] = $this->readyPreview();

        $this->confirm($run);
        $selected = [$run->items()->sole()->id => self::ALL_FIELDS];
        $this->post(route('integrations.sync.confirm', $run), ['selected' => $selected, 'confirm' => '1']);

        $this->assertCount(1, $this->sentWrites());
    }

    public function test_a_successful_sync_records_full_history(): void
    {
        [$link, $run] = $this->readyPreview();

        $this->confirm($run);

        $run->refresh();
        $item = $run->items()->sole();

        $this->assertSame(WooCommerceSyncRun::COMPLETED, $run->status);
        $this->assertNotNull($run->confirmed_at);
        $this->assertSame($this->user->id, $run->user_id);
        $this->assertSame(WooCommerceSyncItem::COMPLETED, $item->status);
        $this->assertSame(1, $item->attempts);
        $this->assertSame($link->id, $item->woocommerce_product_link_id);
        $this->assertSame(101, $item->remote_id);
        $this->assertSame('Remote Product 101', $item->previous_values['name']);
        $this->assertSame('New Title', $item->applied_values['name']);
        $this->assertSame('New Title', $item->fields['name']['proposed_wire']);
        $this->assertNotNull($link->fresh()->last_synced_at);
        $this->assertSame('2026-11-09T12:00:00', $link->fresh()->remote_modified_gmt);
        $this->assertNotNull($this->store->fresh()->last_sync_at);

        $this->get(route('integrations.sync.history'))->assertOk()->assertSee(['Test Shop', 'Completed']);
        $this->get(route('integrations.sync.show', $run))->assertOk()->assertSee('Selected for update: Yes');
    }

    public function test_sync_records_contain_no_credentials(): void
    {
        [, $run] = $this->readyPreview();

        $this->confirm($run);

        $encoded = json_encode($run->fresh()->getAttributes()).json_encode($run->items()->sole()->getAttributes());

        $this->assertStringNotContainsString('cs_test_secret', $encoded);
        $this->assertStringNotContainsString('ck_test_key', $encoded);
    }

    public function test_a_remote_change_after_the_preview_is_a_conflict_and_nothing_is_written(): void
    {
        [, $run] = $this->readyPreview();

        $this->remoteProducts[101]['name'] = 'Edited by shop owner';

        $this->confirm($run);

        $item = $run->items()->sole();

        $this->assertSame(WooCommerceSyncItem::CONFLICT, $item->status);
        $this->assertSame('Edited by shop owner', $item->conflicts['name']);
        $this->assertSame(WooCommerceSyncRun::CONFLICT, $run->fresh()->status);
        $this->assertCount(0, $this->sentWrites());
        $this->assertSame('Edited by shop owner', $this->remoteProducts[101]['name']);

        $this->get(route('integrations.sync.show', $run))->assertSee(['New value in store', 'Edited by shop owner', 'Refresh preview']);
    }

    public function test_a_change_to_an_unselected_field_is_not_a_conflict(): void
    {
        [, $run] = $this->readyPreview();

        $this->remoteProducts[101]['short_description'] = '<p>Someone edited this.</p>';

        $this->confirm($run, ['name']);

        $this->assertSame(WooCommerceSyncItem::COMPLETED, $run->items()->sole()->status);
        $this->assertSame('<p>Someone edited this.</p>', $this->remoteProducts[101]['short_description']);
    }

    public function test_refreshing_a_conflict_builds_a_new_preview_with_the_new_value(): void
    {
        [, $run] = $this->readyPreview();
        $this->remoteProducts[101]['name'] = 'Edited by shop owner';
        $this->confirm($run);

        $this->post(route('integrations.sync.refresh', $run))->assertRedirect();

        $fresh = WooCommerceSyncRun::query()->latest('id')->first();

        $this->assertNotSame($run->id, $fresh->id);
        $this->assertSame('Edited by shop owner', $fresh->items()->sole()->fields['name']['remote_display']);
        $this->assertCount(0, $this->sentWrites());
    }

    public function test_content_changed_after_the_preview_is_not_sent(): void
    {
        [$link, $run] = $this->readyPreview();

        ProductContent::where('product_id', $link->product_id)->update(['title' => 'Edited after preview']);

        $this->confirm($run);

        $this->assertSame(WooCommerceSyncItem::CONFLICT, $run->items()->sole()->status);
        $this->assertCount(0, $this->sentWrites());
    }

    public function test_a_product_whose_type_changed_is_not_written(): void
    {
        [, $run] = $this->readyPreview();

        $this->remoteProducts[101]['type'] = 'grouped';

        $this->confirm($run);

        $this->assertSame(WooCommerceSyncItem::CONFLICT, $run->items()->sole()->status);
        $this->assertCount(0, $this->sentWrites());
    }

    public function test_formatting_only_differences_do_not_count_as_changes(): void
    {
        $this->fakeLiveStore([$this->remoteProduct(101, ['name' => 'New Title', 'description' => "<div>New   long\ndescription.</div>", 'short_description' => '<p>New short description.</p>', 'slug' => 'new-title'])]);
        $link = $this->linkedProduct($this->user, $this->store);
        $this->approvedContent($link);

        $run = $this->preview([$link]);

        $this->assertSame(WooCommerceSyncRun::SKIPPED, $run->status);
        $this->assertStringContainsString('already matches', $run->items()->sole()->message);
    }

    public function test_text_is_escaped_and_wrapped_for_the_store(): void
    {
        [, $run] = $this->readyPreview(['long_description' => "First <b>para</b>.\n\nSecond\nline."]);

        $this->confirm($run, ['description']);

        $this->assertSame("<p>First &lt;b&gt;para&lt;/b&gt;.</p>\n<p>Second<br>\nline.</p>", $this->sentWrites()->sole()->data()['description']);
    }

    public function test_partial_failures_are_reported_and_others_continue(): void
    {
        $this->fakeLiveStore([$this->remoteProduct(1), $this->remoteProduct(2)]);

        $first = $this->linkedProduct($this->user, $this->store, 1);
        $second = $this->linkedProduct($this->user, $this->store, 2);
        $this->approvedContent($first);
        $this->approvedContent($second);

        $run = $this->preview([$first, $second]);

        unset($this->remoteProducts[1]);

        $this->confirm($run);

        $run->refresh();

        $this->assertSame(WooCommerceSyncRun::PARTIALLY_COMPLETED, $run->status);
        $this->assertSame(WooCommerceSyncItem::FAILED, $run->items()->where('remote_id', 1)->sole()->status);
        $this->assertSame(WooCommerceError::NotFound->value, $run->items()->where('remote_id', 1)->sole()->error_code);
        $this->assertSame(WooCommerceSyncItem::COMPLETED, $run->items()->where('remote_id', 2)->sole()->status);
    }

    public function test_a_value_the_store_altered_is_reported_as_partial(): void
    {
        [, $run] = $this->readyPreview();

        $this->writeOverride = fn () => Http::response([...$this->remoteProducts[101], 'name' => 'Sanitized by WordPress']);

        $this->confirm($run, ['name']);

        $item = $run->items()->sole();

        $this->assertSame(WooCommerceSyncItem::PARTIALLY_COMPLETED, $item->status);
        $this->assertSame('Sanitized by WordPress', $item->applied_values['name']);
        $this->assertStringContainsString('Product title', $item->message);
    }

    /**
     * Fakes a store where the write fails once with the given response.
     */
    private function failFirstWrite(mixed $failure): void
    {
        $failed = false;

        $this->writeOverride = function (Request $request) use (&$failed, $failure) {
            if ($failed) {
                return null;
            }

            $failed = true;

            return $failure;
        };
    }

    public function test_a_failed_write_is_never_retried_automatically(): void
    {
        [, $run] = $this->readyPreview();
        $this->failFirstWrite(Http::response([], 503));

        $this->confirm($run);

        $this->assertCount(1, $this->sentWrites());
        $this->assertSame(WooCommerceSyncItem::FAILED, $run->items()->sole()->status);
    }

    public function test_a_lost_response_is_reported_as_unknown(): void
    {
        [, $run] = $this->readyPreview();
        $this->failFirstWrite(Http::failedConnection('cURL error 28: Operation timed out'));

        $this->confirm($run);

        $this->assertSame(WooCommerceError::OutcomeUnknown->value, $run->items()->sole()->error_code);
        $this->assertCount(1, $this->sentWrites());
    }

    public function test_retry_writes_when_the_earlier_attempt_did_not_apply(): void
    {
        [, $run] = $this->readyPreview();
        $this->failFirstWrite(Http::response([], 503));
        $this->confirm($run);

        $this->post(route('integrations.sync.retry', $run))->assertRedirect();

        $item = $run->items()->sole();

        $this->assertSame(WooCommerceSyncItem::COMPLETED, $item->status);
        $this->assertSame(2, $item->attempts);
        $this->assertSame('New Title', $this->remoteProducts[101]['name']);
    }

    public function test_retry_does_not_write_again_when_the_earlier_attempt_was_applied(): void
    {
        [, $run] = $this->readyPreview();

        // The store applies the update, but the response is lost.
        $this->writeOverride = function (Request $request) {
            $this->writeOverride = null;
            $this->remoteProducts[101] = array_replace($this->remoteProducts[101], $request->data());

            return Http::failedConnection('cURL error 28: Operation timed out');
        };

        $this->confirm($run);

        $this->post(route('integrations.sync.retry', $run));

        $this->assertCount(1, $this->sentWrites());
        $this->assertSame(WooCommerceSyncItem::COMPLETED, $run->items()->sole()->status);
    }

    public function test_retry_repeats_only_failed_items(): void
    {
        $this->fakeLiveStore([$this->remoteProduct(1), $this->remoteProduct(2)]);
        $first = $this->linkedProduct($this->user, $this->store, 1);
        $second = $this->linkedProduct($this->user, $this->store, 2);
        $this->approvedContent($first);
        $this->approvedContent($second);
        $run = $this->preview([$first, $second]);

        $saved = $this->remoteProducts[1];
        unset($this->remoteProducts[1]);
        $this->confirm($run);
        $this->remoteProducts[1] = $saved;

        $writesBefore = $this->sentWrites()->count();

        $this->post(route('integrations.sync.retry', $run));

        $this->assertSame($writesBefore + 1, $this->sentWrites()->count());
        $this->assertStringEndsWith('/products/1', $this->sentWrites()->last()->url());
        $this->assertSame(WooCommerceSyncRun::COMPLETED, $run->fresh()->status);
    }

    public function test_a_rate_limited_write_waits_and_tries_again(): void
    {
        Queue::fake();
        [, $run] = $this->readyPreview();
        $this->failFirstWrite(Http::response([], 429, ['Retry-After' => '30']));

        $this->confirm($run);

        Queue::assertPushed(ProcessWooCommerceSyncRun::class);
        $this->assertSame(WooCommerceSyncItem::QUEUED, $run->items()->sole()->status);
    }

    public function test_large_runs_use_the_queue(): void
    {
        config(['woocommerce.sync_inline_items' => 0]);
        Queue::fake();
        $this->fakeLiveStore([$this->remoteProduct()]);
        $link = $this->linkedProduct($this->user, $this->store);
        $this->approvedContent($link);

        $run = $this->preview([$link]);

        Queue::assertPushed(ProcessWooCommerceSyncRun::class, fn ($job) => $job->runId === $run->id);
        $this->get(route('integrations.sync.show', $run))->assertSee('queue worker');
    }

    public function test_batch_size_is_bounded(): void
    {
        config(['woocommerce.sync_max_products' => 1]);
        Http::fake();

        $this->post(route('integrations.sync.store', $this->store), ['language' => 'en', 'link_ids' => [1, 2]])
            ->assertSessionHasErrors('link_ids');
    }

    public function test_a_second_run_cannot_start_while_one_is_active(): void
    {
        Http::fake();
        $link = $this->linkedProduct($this->user, $this->store);
        $this->user->wooCommerceSyncRuns()->create(['woocommerce_store_id' => $this->store->id, 'language' => 'en', 'status' => WooCommerceSyncRun::RUNNING]);

        $this->post(route('integrations.sync.store', $this->store), ['language' => 'en', 'link_ids' => [$link->id]])
            ->assertSessionHasErrors('language');
    }

    public function test_an_item_stuck_running_is_recovered_without_writing_twice(): void
    {
        [, $run] = $this->readyPreview();
        $this->confirm($run);

        $writes = $this->sentWrites()->count();

        $item = $run->items()->sole();
        $item->forceFill(['status' => WooCommerceSyncItem::RUNNING, 'updated_at' => now()->subHour()])->saveQuietly();
        $run->update(['status' => WooCommerceSyncRun::RUNNING]);

        app(WooCommerceSyncService::class)->processNext($run->fresh());

        $this->assertSame($writes, $this->sentWrites()->count());
        $this->assertSame(WooCommerceSyncItem::COMPLETED, $item->fresh()->status);
    }

    public function test_variable_products_sync_only_product_level_fields(): void
    {
        $this->fakeLiveStore([$this->remoteProduct(101, ['type' => 'variable', 'variations' => [11, 12]])]);
        $link = $this->linkedProduct($this->user, $this->store, 101, ['remote_type' => 'variable', 'sync_mode' => WooCommerceProductLink::MODE_DESCRIPTIVE]);
        $this->approvedContent($link);

        $this->confirm($this->preview([$link]));

        $write = $this->sentWrites()->sole();

        $this->assertStringEndsWith('/products/101', $write->url());
        $this->assertSame(self::ALL_FIELDS, array_keys($write->data()));
        $this->assertSame([11, 12], $this->remoteProducts[101]['variations']);
    }

    public function test_read_only_product_types_are_skipped(): void
    {
        $this->fakeLiveStore([$this->remoteProduct(101, ['type' => 'grouped'])]);
        $link = $this->linkedProduct($this->user, $this->store, 101, ['remote_type' => 'grouped', 'sync_mode' => WooCommerceProductLink::MODE_READ_ONLY]);
        $this->approvedContent($link);

        $run = $this->preview([$link]);

        $this->assertSame(WooCommerceSyncRun::SKIPPED, $run->status);
        $this->assertStringContainsString('read-only', $run->items()->sole()->message);
    }

    public function test_seo_fields_are_shown_as_unsupported_and_never_sent(): void
    {
        [, $run] = $this->readyPreview(['meta_title' => 'SEO title here', 'meta_description' => 'SEO description here']);

        $this->get(route('integrations.sync.show', $run))->assertSee(['SEO Sync Not Supported', 'SEO title here']);

        $selected = [$run->items()->sole()->id => ['name', 'meta_title', 'meta_description']];
        $this->post(route('integrations.sync.confirm', $run), ['selected' => $selected, 'confirm' => '1']);

        $this->assertSame(['name'], array_keys($this->sentWrites()->sole()->data()));
    }

    public function test_a_single_language_store_refuses_other_languages(): void
    {
        Http::fake();
        $link = $this->linkedProduct($this->user, $this->store);
        ProductContent::factory()->for($link->product)->language(ContentLanguage::German)->ai()->create();

        $this->post(route('integrations.sync.store', $this->store), ['language' => 'de', 'link_ids' => [$link->id]])
            ->assertSessionHasErrors('language');

        Http::assertNothingSent();
    }

    private function wpmlStore(): WooCommerceStore
    {
        return $this->connectedStore($this->user, [
            'url' => 'https://multi.example.com',
            'capabilities' => ['language' => ['provider' => 'wpml', 'supported' => true, 'default' => 'en', 'reason' => null], 'seo' => ['provider' => null, 'writable' => false, 'reason' => '']],
        ]);
    }

    public function test_wpml_writes_to_the_translation_of_the_chosen_language(): void
    {
        $store = $this->wpmlStore();
        $this->fakeLiveStore([$this->remoteProduct(101), $this->remoteProduct(202)]);
        $link = $this->linkedProduct($this->user, $store, 101, ['remote_language' => 'en', 'translations' => ['de' => 202]]);
        ProductContent::factory()->for($link->product)->language(ContentLanguage::German)->ai()->create(['title' => 'Deutscher Titel']);
        ProductContent::factory()->for($link->product)->create(['title' => 'English Title']);

        $this->confirm($this->preview([$link], 'de', $store), ['name']);

        $write = $this->sentWrites()->sole();

        $this->assertStringEndsWith('/products/202', $write->url());
        $this->assertSame('Deutscher Titel', $write->data()['name']);
        $this->assertSame('Remote Product 101', $this->remoteProducts[101]['name']);
    }

    public function test_wpml_never_creates_missing_translations(): void
    {
        $store = $this->wpmlStore();
        $this->fakeLiveStore([$this->remoteProduct(101)]);
        $link = $this->linkedProduct($this->user, $store, 101, ['remote_language' => 'en', 'translations' => []]);
        ProductContent::factory()->for($link->product)->language(ContentLanguage::Albanian)->ai()->create();

        $run = $this->preview([$link], 'sq', $store);

        $this->assertSame(WooCommerceSyncRun::SKIPPED, $run->status);
        $this->assertStringContainsString('no Albanian version', $run->items()->sole()->message);
        $this->assertCount(0, $this->sentWrites());
    }

    public function test_an_unsupported_translation_plugin_disables_sync_with_an_explanation(): void
    {
        Http::fake();
        $store = $this->connectedStore($this->user, [
            'url' => 'https://poly.example.com',
            'capabilities' => ['language' => ['provider' => 'polylang', 'supported' => false, 'default' => null, 'reason' => 'Plugin not verified.'], 'seo' => ['provider' => null, 'writable' => false, 'reason' => '']],
        ]);
        $link = $this->linkedProduct($this->user, $store);

        $this->post(route('integrations.sync.store', $store), ['language' => 'en', 'link_ids' => [$link->id]])
            ->assertSessionHasErrors(['language' => 'Plugin not verified.']);

        $this->get(route('integrations.products', $store))->assertSee('Plugin not verified.');
        Http::assertNothingSent();
    }

    public function test_rollback_needs_its_own_preview_and_confirmation(): void
    {
        [, $run] = $this->readyPreview();
        $this->confirm($run, ['name']);

        $this->post(route('integrations.sync.rollback', $run))->assertRedirect();

        $rollback = WooCommerceSyncRun::query()->latest('id')->first();

        $this->assertSame($run->id, $rollback->rollback_of_id);
        $this->assertSame(WooCommerceSyncRun::READY, $rollback->status);
        $this->assertCount(1, $this->sentWrites());
        $this->assertSame('New Title', $this->remoteProducts[101]['name']);

        $this->get(route('integrations.sync.show', $rollback))->assertSee(['Rollback preview', 'Restored value', 'Remote Product 101']);
    }

    public function test_a_confirmed_rollback_restores_the_previous_value(): void
    {
        [, $run] = $this->readyPreview();
        $this->confirm($run, ['name', 'description']);

        $this->post(route('integrations.sync.rollback', $run));
        $rollback = WooCommerceSyncRun::query()->latest('id')->first();

        $this->confirm($rollback, ['name', 'description']);

        $this->assertSame('Remote Product 101', $this->remoteProducts[101]['name']);
        $this->assertSame('<p>Old long description.</p>', $this->remoteProducts[101]['description']);
        $this->assertSame(WooCommerceSyncRun::COMPLETED, $rollback->fresh()->status);
    }

    public function test_rollback_refuses_fields_changed_since_the_sync(): void
    {
        [, $run] = $this->readyPreview();
        $this->confirm($run, ['name']);

        $this->remoteProducts[101]['name'] = 'Changed again by owner';

        $this->post(route('integrations.sync.rollback', $run));
        $rollback = WooCommerceSyncRun::query()->latest('id')->first();

        $this->assertSame(WooCommerceSyncRun::CONFLICT, $rollback->status);
        $this->assertSame(WooCommerceSyncItem::CONFLICT, $rollback->items()->sole()->status);
        $this->assertTrue($rollback->items()->sole()->fields['name']['blocked']);
        $this->assertCount(1, $this->sentWrites());
    }

    public function test_a_rollback_with_nothing_recorded_is_refused(): void
    {
        Http::fake();
        $run = $this->user->wooCommerceSyncRuns()->create(['woocommerce_store_id' => $this->store->id, 'language' => 'en', 'status' => WooCommerceSyncRun::FAILED]);

        $this->post(route('integrations.sync.rollback', $run))->assertSessionHasErrors('confirm');
    }

    public function test_a_disconnected_store_stops_queued_sync_without_contacting_it(): void
    {
        [, $run] = $this->readyPreview();

        app(WooCommerceConnectionService::class)->disconnect($this->store);
        $requests = count(Http::recorded());

        $this->assertSame(WooCommerceSyncRun::FAILED, $run->fresh()->status);

        $selected = [$run->items()->sole()->id => ['name']];
        $this->post(route('integrations.sync.confirm', $run), ['selected' => $selected, 'confirm' => '1']);

        $this->assertCount($requests, Http::recorded());
        $this->assertCount(0, $this->sentWrites());
    }

    public function test_history_survives_a_deleted_local_product(): void
    {
        [$link, $run] = $this->readyPreview();
        $this->confirm($run);

        $link->product->delete();

        $this->assertModelExists($run);
        $this->assertNull($run->items()->sole()->product_id);
        $this->get(route('integrations.sync.show', $run))->assertOk();
    }

    public function test_sync_runs_and_links_are_protected_from_other_users(): void
    {
        [$link, $run] = $this->readyPreview();
        $other = User::factory()->create();
        $this->actingAs($other);

        $this->get(route('integrations.sync.show', $run))->assertForbidden();
        $this->post(route('integrations.sync.confirm', $run), ['selected' => [1 => ['name']], 'confirm' => '1'])->assertForbidden();
        $this->post(route('integrations.sync.retry', $run))->assertForbidden();
        $this->post(route('integrations.sync.refresh', $run))->assertForbidden();
        $this->post(route('integrations.sync.rollback', $run))->assertForbidden();
        $this->get(route('integrations.sync.history'))->assertDontSee('Test Shop');

        $this->assertCount(0, $this->sentWrites());
    }

    public function test_links_from_another_store_cannot_be_smuggled_in(): void
    {
        Http::fake();
        $foreignOwner = User::factory()->create();
        $foreignStore = $this->connectedStore($foreignOwner, ['url' => 'https://foreign.example.com']);
        $foreignLink = $this->linkedProduct($foreignOwner, $foreignStore);

        $this->post(route('integrations.sync.store', $this->store), ['language' => 'en', 'link_ids' => [$foreignLink->id]])
            ->assertSessionHasErrors('language');

        $this->assertDatabaseCount('woocommerce_sync_runs', 0);
    }
}
