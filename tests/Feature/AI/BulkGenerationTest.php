<?php

namespace Tests\Feature\AI;

use App\Enums\AiError;
use App\Enums\BulkItemStatus;
use App\Enums\ContentLanguage;
use App\Enums\ContentSource;
use App\Enums\ProductStatus;
use App\Jobs\RunBulkGeneration;
use App\Models\AiGeneration;
use App\Models\BulkGenerationBatch;
use App\Models\BulkGenerationItem;
use App\Models\Product;
use App\Models\ProductContent;
use App\Models\User;
use App\Services\AI\BulkGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Concerns\FakesGemini;
use Tests\TestCase;

class BulkGenerationTest extends TestCase
{
    use FakesGemini, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['services.gemini.per_minute_limit' => 100]);

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    private function products(int $count = 2)
    {
        return Product::factory()->for($this->user)->count($count)->create();
    }

    private function start($products, array $data = [])
    {
        return $this->post(route('ai.bulk.store'), [
            'product_ids' => $products->modelKeys(),
            'languages' => ['en'],
            'submission_token' => (string) Str::uuid(),
            'confirm' => '1',
            ...$data,
        ]);
    }

    private function runAll(): void
    {
        $service = app(BulkGenerationService::class);
        $batch = BulkGenerationBatch::query()->latest('id')->firstOrFail();

        for ($guard = 0; $guard < 50; $guard++) {
            $delay = $service->processNext($batch->id);

            if ($delay === null || $delay > 0) {
                return;
            }
        }
    }

    public function test_the_confirmation_page_shows_the_estimate_inputs(): void
    {
        $products = $this->products();

        $this->get(route('ai.bulk.create', ['product_ids' => $products->modelKeys()]))
            ->assertOk()
            ->assertSee(['Selected products', 'Target languages', 'Estimated AI requests', 'Generations left today', 'Actual token usage varies', 'Advanced Options']);
    }

    public function test_starting_requires_explicit_confirmation(): void
    {
        Http::fake();

        $this->start($this->products(), ['confirm' => null])->assertSessionHasErrors('confirm');

        $this->assertDatabaseCount('bulk_generation_batches', 0);
        Http::assertNothingSent();
    }

    public function test_starting_queues_the_work_without_calling_gemini(): void
    {
        Http::fake();

        $this->start($this->products(), ['languages' => ['en', 'de']])->assertRedirect();

        $this->assertDatabaseCount('bulk_generation_items', 4);
        Queue::assertPushed(RunBulkGeneration::class);
        Http::assertNothingSent();
    }

    public function test_a_double_submission_creates_one_batch(): void
    {
        $products = $this->products();
        $token = (string) Str::uuid();

        $this->start($products, ['submission_token' => $token]);
        $this->start($products, ['submission_token' => $token]);

        $this->assertDatabaseCount('bulk_generation_batches', 1);
        $this->assertDatabaseCount('bulk_generation_items', 2);
    }

    public function test_a_batch_over_the_daily_allowance_is_refused_and_explained(): void
    {
        config(['services.gemini.user_daily_limit' => 3]);

        $this->start($this->products(2), ['languages' => ['en', 'de']])
            ->assertSessionHasErrors(['product_ids' => 'This batch needs 4 AI requests, but only 3 are left today. Choose fewer products or languages.']);

        $this->assertDatabaseCount('bulk_generation_batches', 0);
    }

    public function test_a_smaller_batch_within_the_allowance_is_accepted(): void
    {
        config(['services.gemini.user_daily_limit' => 3]);

        $this->start($this->products(3))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('bulk_generation_items', 3);
    }

    public function test_products_without_facts_are_left_out(): void
    {
        $good = $this->products(1);
        $bare = Product::factory()->for($this->user)->create(['category' => null, 'material' => null, 'details' => null]);

        $this->start($good->concat([$bare]));

        $this->assertSame($good->modelKeys(), BulkGenerationItem::pluck('product_id')->all());
    }

    public function test_other_users_products_are_left_out(): void
    {
        $mine = $this->products(1);
        $foreign = Product::factory()->create();

        $this->start($mine->concat([$foreign]));

        $this->assertSame($mine->modelKeys(), BulkGenerationItem::pluck('product_id')->all());
    }

    public function test_generation_uses_the_existing_service_settings_and_history(): void
    {
        Http::fake(['*' => Http::response($this->geminiBody())]);

        $this->start($this->products(1), ['tone' => 'luxury', 'custom_instructions' => 'Keep it short.']);
        $this->runAll();

        $item = BulkGenerationItem::sole();
        $generation = AiGeneration::sole();

        $this->assertSame(BulkItemStatus::Generated, $item->status);
        $this->assertSame(BulkGenerationItem::REVIEW_PENDING, $item->review_status);
        $this->assertSame($generation->id, $item->ai_generation_id);
        $this->assertSame('luxury', $generation->preferences['tone']);
        $this->assertTrue($generation->preferences['has_custom_instructions']);
        $this->assertSame(220, $generation->total_tokens);

        Http::assertSent(fn (Request $request) => str_contains($request['systemInstruction']['parts'][0]['text'], 'Luxury')
            && str_contains($request['contents'][0]['parts'][0]['text'], 'Keep it short.'));
    }

    public function test_generation_never_saves_content_or_publishes(): void
    {
        Http::fake(['*' => Http::response($this->geminiBody())]);
        $products = $this->products(1);

        $this->start($products);
        $this->runAll();

        $this->assertDatabaseCount('product_contents', 0);
        $this->assertSame(ProductStatus::Draft, $products->first()->fresh()->status);
    }

    public function test_it_makes_one_request_per_product_and_language(): void
    {
        Http::fake(['*' => Http::response($this->geminiBody())]);

        $this->start($this->products(2), ['languages' => ['en', 'sq', 'de']]);
        $this->runAll();

        Http::assertSentCount(6);
    }

    public function test_it_waits_when_the_per_minute_limit_is_reached(): void
    {
        config(['services.gemini.per_minute_limit' => 1]);
        Http::fake(['*' => Http::response($this->geminiBody())]);

        $this->start($this->products(2));
        $batch = BulkGenerationBatch::sole();
        $service = app(BulkGenerationService::class);

        $this->assertSame(0, $service->processNext($batch->id));
        $this->assertGreaterThan(0, $service->processNext($batch->id));

        Http::assertSentCount(1);
        $this->assertSame(1, BulkGenerationItem::where('status', BulkItemStatus::Queued)->count());
    }

    public function test_bulk_requests_share_the_interactive_rate_limit(): void
    {
        config(['services.gemini.per_minute_limit' => 1]);
        Http::fake(['*' => Http::response($this->geminiBody())]);
        $products = $this->products(2);

        $this->start($products);
        app(BulkGenerationService::class)->processNext(BulkGenerationBatch::sole()->id);

        $this->postJson(route('ai.generate', $products->first()), ['language' => 'en'])
            ->assertStatus(429)
            ->assertJsonPath('code', AiError::RateLimited->value);
    }

    public function test_an_interactive_rate_limit_also_pauses_the_batch(): void
    {
        config(['services.gemini.per_minute_limit' => 1]);
        Http::fake(['*' => Http::response($this->geminiBody())]);

        RateLimiter::hit(md5('ai-generate'.$this->user->id), 60);

        $this->start($this->products(1));

        $this->assertGreaterThan(0, app(BulkGenerationService::class)->processNext(BulkGenerationBatch::sole()->id));
        Http::assertNothingSent();
    }

    public function test_running_out_of_the_daily_allowance_stops_the_batch_clearly(): void
    {
        Http::fake(['*' => Http::response($this->geminiBody())]);

        $this->start($this->products(3));
        config(['services.gemini.user_daily_limit' => 1]);
        $this->runAll();

        $this->assertSame(1, BulkGenerationItem::where('status', BulkItemStatus::Generated)->count());
        $this->assertSame(2, BulkGenerationItem::where('error_code', AiError::DailyLimitReached->value)->count());
        $this->assertSame(BulkGenerationBatch::COMPLETED_WITH_FAILURES, BulkGenerationBatch::sole()->status);
        Http::assertSentCount(1);
    }

    public function test_one_failure_does_not_stop_the_others(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push($this->geminiBody('not json'))
            ->push($this->geminiBody())]);

        $this->start($this->products(2));
        $this->runAll();

        $this->assertSame(1, BulkGenerationItem::where('status', BulkItemStatus::Failed)->count());
        $this->assertSame(1, BulkGenerationItem::where('status', BulkItemStatus::Generated)->count());
        $this->get(route('ai.reviews.index', ['status' => 'failed']))->assertSee(AiError::InvalidResponse->message());
    }

    public function test_an_invalid_key_stops_the_batch_instead_of_repeating(): void
    {
        Http::fake(['*' => Http::response($this->geminiError(403, 'PERMISSION_DENIED'), 403)]);

        $this->start($this->products(3));
        $this->runAll();

        Http::assertSentCount(1);
        $this->assertSame(3, BulkGenerationItem::where('status', BulkItemStatus::Failed)->count());
    }

    public function test_a_gemini_quota_error_waits_and_tries_again(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push($this->geminiError(429, 'RESOURCE_EXHAUSTED', details: [['retryDelay' => '30s']]), 429)
            ->push($this->geminiBody())]);

        $this->start($this->products(1));
        $batch = BulkGenerationBatch::sole();
        $service = app(BulkGenerationService::class);

        $this->assertSame(30, $service->processNext($batch->id));
        $this->assertSame(BulkItemStatus::Queued, BulkGenerationItem::sole()->status);

        $service->processNext($batch->id);

        $this->assertSame(BulkItemStatus::Generated, BulkGenerationItem::sole()->status);
    }

    public function test_it_waits_when_another_generation_is_running(): void
    {
        Http::fake();
        $this->start($this->products(1));

        $lock = Cache::lock("ai-generation:user:{$this->user->id}", 60);
        $lock->get();

        $this->assertSame(15, app(BulkGenerationService::class)->processNext(BulkGenerationBatch::sole()->id));
        $this->assertSame(BulkItemStatus::Queued, BulkGenerationItem::sole()->status);

        $lock->release();
    }

    public function test_retry_repeats_only_failed_items(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push($this->geminiBody())
            ->push($this->geminiBody('not json'))
            ->push($this->geminiBody(['title' => 'Second try']))]);

        $this->start($this->products(2));
        $this->runAll();

        $this->post(route('ai.bulk.retry', BulkGenerationBatch::sole()))->assertSessionHasNoErrors();
        $this->runAll();

        Http::assertSentCount(3);
        $this->assertSame(2, BulkGenerationItem::where('status', BulkItemStatus::Generated)->count());
        $this->assertSame(BulkGenerationBatch::COMPLETED, BulkGenerationBatch::sole()->status);
    }

    public function test_retry_respects_the_remaining_allowance(): void
    {
        Http::fake(['*' => Http::response($this->geminiError(400, 'INVALID_ARGUMENT'), 400)]);

        $this->start($this->products(3));
        $this->runAll();

        config(['services.gemini.user_daily_limit' => 4]);

        $this->post(route('ai.bulk.retry', BulkGenerationBatch::sole()))->assertSessionHas('status');

        $this->assertSame(1, BulkGenerationItem::where('status', BulkItemStatus::Queued)->count());
        $this->assertSame(2, BulkGenerationItem::where('status', BulkItemStatus::Failed)->count());
    }

    public function test_another_users_batch_cannot_be_retried(): void
    {
        $this->start($this->products(1));
        $batch = BulkGenerationBatch::sole();
        $this->actingAs(User::factory()->create());

        $this->post(route('ai.bulk.retry', $batch))->assertForbidden();
    }

    public function test_batch_size_is_limited(): void
    {
        config(['catalog.bulk_generation_max_products' => 1]);

        $this->start($this->products(2))->assertSessionHasErrors('product_ids');
    }

    /**
     * @return array{BulkGenerationItem, Product}
     */
    private function generatedItem(array $content = []): array
    {
        Http::fake(['*' => Http::response($this->geminiBody($content))]);

        $products = $this->products(1);
        $this->start($products);
        $this->runAll();

        return [BulkGenerationItem::sole(), $products->first()];
    }

    private function reviewFields(array $overrides = []): array
    {
        return [...$this->generatedFields(), 'keywords' => 'leather tote, tote bag', 'action' => 'approve', ...$overrides];
    }

    public function test_items_appear_in_the_review_queue_by_status(): void
    {
        [$item, $product] = $this->generatedItem();

        $this->get(route('ai.reviews.index'))->assertOk()->assertSee($product->original_name);
        $this->get(route('ai.reviews.index', ['status' => 'approved']))->assertDontSee($product->original_name);
    }

    public function test_the_review_page_shows_content_and_analysis(): void
    {
        [$item] = $this->generatedItem();

        $this->get(route('ai.reviews.edit', $item))->assertOk()->assertSee(['Leather Tote Bag', 'SEO Quality', 'Approve']);
    }

    public function test_edits_can_be_saved_without_approving(): void
    {
        [$item] = $this->generatedItem();

        $this->put(route('ai.reviews.update', $item), $this->reviewFields(['title' => 'Edited', 'action' => 'save']))->assertRedirect();

        $item->refresh();

        $this->assertSame('Edited', $item->edited_content['title']);
        $this->assertSame(BulkGenerationItem::REVIEW_PENDING, $item->review_status);
        $this->assertDatabaseCount('product_contents', 0);
    }

    public function test_approving_saves_the_edited_content_without_publishing(): void
    {
        [$item, $product] = $this->generatedItem();

        $this->put(route('ai.reviews.update', $item), $this->reviewFields(['title' => 'Approved Title', 'slug' => 'Approved Title!']))->assertSessionHasNoErrors();

        $content = ProductContent::sole();

        $this->assertSame('Approved Title', $content->title);
        $this->assertSame('approved-title', $content->slug);
        $this->assertSame(ContentSource::Ai, $content->source);
        $this->assertSame(BulkGenerationItem::REVIEW_APPROVED, $item->fresh()->review_status);
        $this->assertNotNull($item->generation->fresh()->saved_at);
        $this->assertSame(ProductStatus::Draft, $product->fresh()->status);
    }

    public function test_approving_never_replaces_existing_content_without_confirmation(): void
    {
        [$item, $product] = $this->generatedItem();
        ProductContent::factory()->for($product)->create(['title' => 'Handwritten']);

        $this->put(route('ai.reviews.update', $item), $this->reviewFields())->assertSessionHasErrors('confirm_overwrite');

        $this->assertSame('Handwritten', ProductContent::sole()->title);
        $this->assertSame(BulkGenerationItem::REVIEW_PENDING, $item->fresh()->review_status);

        $this->put(route('ai.reviews.update', $item), $this->reviewFields(['confirm_overwrite' => '1']))->assertSessionHasNoErrors();

        $this->assertSame('Leather Tote Bag', ProductContent::sole()->title);
    }

    public function test_review_input_is_validated(): void
    {
        [$item] = $this->generatedItem();

        $this->put(route('ai.reviews.update', $item), $this->reviewFields(['title' => '']))->assertSessionHasErrors('title');

        $this->assertDatabaseCount('product_contents', 0);
    }

    public function test_multiple_items_can_be_approved_and_conflicts_are_left_pending(): void
    {
        Http::fake(['*' => Http::response($this->geminiBody())]);
        $products = $this->products(2);
        $this->start($products);
        $this->runAll();

        ProductContent::factory()->for($products[0])->create(['title' => 'Handwritten']);

        $this->post(route('ai.reviews.approve'), ['item_ids' => BulkGenerationItem::pluck('id')->all()])
            ->assertSessionHas('status', fn (string $message) => str_contains($message, '1 approved') && str_contains($message, '1 left pending'));

        $this->assertSame('Handwritten', ProductContent::where('product_id', $products[0]->id)->sole()->title);
        $this->assertSame('Leather Tote Bag', ProductContent::where('product_id', $products[1]->id)->sole()->title);
    }

    public function test_bulk_approval_can_replace_with_explicit_confirmation(): void
    {
        [$item, $product] = $this->generatedItem();
        ProductContent::factory()->for($product)->create(['title' => 'Handwritten']);

        $this->post(route('ai.reviews.approve'), ['item_ids' => [$item->id], 'confirm_overwrite' => '1']);

        $this->assertSame('Leather Tote Bag', ProductContent::sole()->title);
    }

    public function test_a_failed_item_cannot_be_approved(): void
    {
        Http::fake(['*' => Http::response($this->geminiBody('not json'))]);
        $this->start($this->products(1));
        $this->runAll();

        $item = BulkGenerationItem::sole();

        $this->post(route('ai.reviews.approve'), ['item_ids' => [$item->id]]);

        $this->assertDatabaseCount('product_contents', 0);
        $this->assertNull($item->fresh()->review_status);
    }

    public function test_another_users_items_are_protected(): void
    {
        [$item] = $this->generatedItem();

        $this->actingAs(User::factory()->create());

        $this->get(route('ai.reviews.edit', $item))->assertForbidden();
        $this->put(route('ai.reviews.update', $item), $this->reviewFields())->assertForbidden();
        $this->post(route('ai.reviews.approve'), ['item_ids' => [$item->id]]);

        $this->assertDatabaseCount('product_contents', 0);
    }

    public function test_bulk_generations_appear_in_history_and_statistics(): void
    {
        Http::fake(['*' => Http::response($this->geminiBody())]);
        $this->start($this->products(2));
        $this->runAll();

        $this->assertSame(2, $this->get(route('dashboard'))->viewData('stats')['generations']);
        $this->get(route('generation-history'))->assertSee('Succeeded');
    }

    public function test_the_language_is_stored_as_the_existing_code(): void
    {
        Http::fake(['*' => Http::response($this->geminiBody())]);
        $this->start($this->products(1), ['languages' => ['sq']]);
        $this->runAll();

        $this->assertSame(ContentLanguage::Albanian, AiGeneration::sole()->language);
    }
}
