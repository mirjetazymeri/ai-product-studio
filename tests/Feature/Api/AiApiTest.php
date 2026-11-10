<?php

namespace Tests\Feature\Api;

use App\Enums\AiError;
use App\Enums\AiGenerationStatus;
use App\Enums\BulkItemStatus;
use App\Jobs\GenerateQueuedContent;
use App\Models\AiGeneration;
use App\Models\ApiIdempotencyKey;
use App\Models\BulkGenerationItem;
use App\Models\Product;
use App\Models\ProductContent;
use App\Models\User;
use App\Services\AI\ContentApprovalService;
use App\Services\AI\ProductContentGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\FakesGemini;
use Tests\Concerns\UsesApiTokens;
use Tests\TestCase;

class AiApiTest extends TestCase
{
    use FakesGemini, RefreshDatabase, UsesApiTokens;

    private const PROMPT = 'A 3D printed gaming controller stand for PS5 controllers, made from PLA, SKU GCS-100.';

    private User $user;

    private string $token;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->token = $this->tokenFor($this->user);
        $this->product = Product::factory()->for($this->user)->create(['details' => 'Full-grain leather.']);
    }

    private function client(array $headers = []): static
    {
        return $this->api($this->token)->withHeaders($headers);
    }

    private function generate(array $body = ['language' => 'en'], array $headers = [])
    {
        return $this->client($headers)->postJson("/api/v1/products/{$this->product->id}/generate", $body);
    }

    private function fakeGeminiSuccess(): void
    {
        Http::fake(['*' => Http::response($this->geminiBody())]);
    }

    private function runQueuedGeneration(int $id, ?string $instructions = null): ?int
    {
        return app(ProductContentGenerator::class)->processQueued($id, $instructions);
    }

    public function test_generation_is_accepted_without_calling_gemini(): void
    {
        Queue::fake();
        Http::fake();

        $response = $this->generate();
        $generation = AiGeneration::query()->sole();

        $response->assertAccepted()
            ->assertHeader('Location', route('api.v1.generations.show', $generation))
            ->assertExactJson(['data' => ['generation_id' => $generation->id, 'status' => 'queued', 'product_id' => $this->product->id, 'language' => 'en']]);

        $this->assertSame(AiGenerationStatus::Queued, $generation->status);
        $this->assertNull($generation->generated_content);
        Queue::assertPushed(GenerateQueuedContent::class, fn ($job) => $job->generationId === $generation->id);
        Http::assertNothingSent();
    }

    public function test_status_can_be_polled_through_to_a_result(): void
    {
        Queue::fake();
        $this->fakeGeminiSuccess();

        $id = $this->generate(['language' => 'de', 'tone' => 'friendly'])->json('data.generation_id');

        $this->client()->getJson("/api/v1/generations/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.content', null)
            ->assertJsonPath('data.usage', null);

        $this->runQueuedGeneration($id);

        $response = $this->client()->getJson("/api/v1/generations/{$id}")
            ->assertOk()
            ->assertJsonPath('data.generation_id', $id)
            ->assertJsonPath('data.product_id', $this->product->id)
            ->assertJsonPath('data.language', 'de')
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.content.title', 'Leather Tote Bag')
            ->assertJsonPath('data.usage.total_tokens', 220)
            ->assertJsonPath('data.review_status', BulkGenerationItem::REVIEW_PENDING)
            ->assertJsonPath('data.error', null);

        $this->assertStringNotContainsString('systemInstruction', $response->getContent());
        $this->assertStringNotContainsString('prompt', $response->getContent());
    }

    public function test_preferences_and_custom_instructions_reach_the_existing_prompt(): void
    {
        Queue::fake();
        $this->fakeGeminiSuccess();

        $this->generate(['language' => 'en', 'tone' => 'luxury', 'length' => 'short', 'custom_instructions' => 'Mention the warranty card.']);

        Queue::assertPushed(GenerateQueuedContent::class, function ($job) {
            $this->runQueuedGeneration($job->generationId, $job->customInstructions);

            return true;
        });

        Http::assertSent(fn (Request $request) => str_contains(json_encode($request->data()), 'Mention the warranty card.'));

        $this->assertStringNotContainsString('warranty', (string) json_encode(AiGeneration::query()->sole()->preferences));
    }

    public function test_generation_input_is_validated(): void
    {
        Queue::fake();

        $this->generate(['language' => 'fr'])->assertUnprocessable()->assertJsonValidationErrors('language');
        $this->generate(['language' => 'en', 'tone' => 'angry'])->assertUnprocessable()->assertJsonValidationErrors('tone');
        $this->generate(['language' => 'en', 'custom_instructions' => str_repeat('x', 1001)])->assertUnprocessable();

        Queue::assertNothingPushed();
    }

    public function test_a_product_without_enough_data_is_rejected_without_using_quota(): void
    {
        Queue::fake();
        $this->product->update(['details' => null, 'category' => null, 'material' => null]);

        $this->generate()->assertUnprocessable()->assertJsonPath('code', 'missing_product_data');

        $this->assertSame(0, AiGeneration::query()->count());
    }

    public function test_a_failed_generation_reports_a_safe_error(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response($this->geminiError(400, 'INVALID_ARGUMENT', 'API key not valid. leaked-detail'), 400)]);

        $id = $this->generate()->json('data.generation_id');
        $this->runQueuedGeneration($id);

        $response = $this->client()->getJson("/api/v1/generations/{$id}")->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.content', null);

        $this->assertNotNull($response->json('data.error.code'));
        $response->assertDontSee('leaked-detail')->assertDontSee('GEMINI_API_KEY');
        $this->assertSame(0, BulkGenerationItem::query()->count());
    }

    public function test_server_configuration_isnt_revealed(): void
    {
        Queue::fake();
        config(['services.gemini.api_key' => null]);

        $this->generate()->assertServiceUnavailable()->assertJsonPath('code', 'ai_unavailable')->assertDontSee('GEMINI_API_KEY');
    }

    public function test_a_lost_job_marks_the_generation_failed(): void
    {
        Queue::fake();

        $id = $this->generate()->json('data.generation_id');

        (new GenerateQueuedContent($id))->failed(new \RuntimeException('worker died'));

        $this->client()->getJson("/api/v1/generations/{$id}")
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error.code', AiError::Interrupted->value)
            ->assertDontSee('worker died');
    }

    public function test_a_retried_job_doesnt_call_gemini_twice(): void
    {
        Queue::fake();
        $this->fakeGeminiSuccess();

        $id = $this->generate()->json('data.generation_id');

        $this->runQueuedGeneration($id);
        $this->runQueuedGeneration($id);

        Http::assertSentCount(1);
        $this->assertSame(1, BulkGenerationItem::query()->count());
    }

    public function test_a_result_waits_for_review_and_never_touches_saved_content(): void
    {
        Queue::fake();
        $this->fakeGeminiSuccess();
        $approved = ProductContent::factory()->for($this->product)->ai()->create(['language' => 'en', 'title' => 'Already approved']);

        $id = $this->generate()->json('data.generation_id');
        $this->runQueuedGeneration($id);

        $this->assertSame('Already approved', $approved->fresh()->title);
        $this->client()->getJson("/api/v1/products/{$this->product->id}/approved-content?language=en")->assertJsonPath('data.title', 'Already approved');

        $item = BulkGenerationItem::query()->sole();
        $this->assertSame(BulkItemStatus::Generated, $item->status);
        $this->assertSame(BulkGenerationItem::REVIEW_PENDING, $item->review_status);

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->user)->get(route('ai.reviews.index'))->assertSee($this->product->original_name);
    }

    public function test_a_draft_is_invisible_until_approved_then_available(): void
    {
        Queue::fake();
        $this->fakeGeminiSuccess();

        $id = $this->generate()->json('data.generation_id');
        $this->runQueuedGeneration($id);

        $uri = "/api/v1/products/{$this->product->id}/approved-content?language=en";

        $this->client()->getJson($uri)->assertNotFound();

        app(ContentApprovalService::class)->approve(BulkGenerationItem::query()->sole(), null, false);

        $this->client()->getJson($uri)->assertOk()->assertJsonPath('data.title', 'Leather Tote Bag')->assertJsonPath('data.source', 'ai');
        $this->client()->getJson("/api/v1/generations/{$id}")->assertJsonPath('data.review_status', BulkGenerationItem::REVIEW_APPROVED);
    }

    public function test_another_users_generation_is_not_found(): void
    {
        $generation = AiGeneration::factory()->for(User::factory()->create())->create();

        $this->client()->getJson("/api/v1/generations/{$generation->id}")->assertNotFound();
    }

    public function test_daily_quota_applies_and_counts_queued_work(): void
    {
        Queue::fake();
        config(['services.gemini.user_daily_limit' => 1]);
        $second = Product::factory()->for($this->user)->create(['details' => 'x']);

        $this->generate()->assertAccepted();

        $this->client()->postJson("/api/v1/products/{$second->id}/generate", ['language' => 'en'])
            ->assertTooManyRequests()
            ->assertJsonPath('code', 'daily_limit_reached')
            ->assertHeader('Retry-After');

        $this->assertSame(1, AiGeneration::query()->count());
    }

    public function test_the_application_wide_quota_also_applies(): void
    {
        Queue::fake();
        config(['services.gemini.app_daily_limit' => 1]);
        AiGeneration::factory()->for(User::factory()->create())->create();

        $this->generate()->assertTooManyRequests()->assertJsonPath('code', 'daily_limit_reached');
    }

    public function test_the_gemini_per_minute_limit_paces_the_queue(): void
    {
        Queue::fake();
        Http::fake();
        config(['services.gemini.per_minute_limit' => 1]);
        $key = md5('ai-generate'.$this->user->id);
        RateLimiter::hit($key, 60);

        $id = $this->generate()->json('data.generation_id');

        $this->assertGreaterThan(0, $this->runQueuedGeneration($id));
        $this->assertSame(AiGenerationStatus::Queued, AiGeneration::query()->find($id)->status);
        Http::assertNothingSent();
    }

    public function test_a_second_request_for_the_same_product_and_language_is_refused(): void
    {
        Queue::fake();

        $first = $this->generate()->json('data.generation_id');

        $this->generate()->assertConflict()->assertJsonPath('code', 'duplicate_request')->assertJsonPath('generation_id', $first);
        $this->generate(['language' => 'de'])->assertAccepted();

        Queue::assertPushed(GenerateQueuedContent::class, 2);
    }

    public function test_queued_work_older_than_the_expiry_never_spends_tokens(): void
    {
        Queue::fake();
        Http::fake();

        $id = $this->generate()->json('data.generation_id');

        $this->travel(25)->hours();
        $this->runQueuedGeneration($id);

        $this->assertSame(AiGenerationStatus::Failed, AiGeneration::query()->find($id)->status);
        Http::assertNothingSent();
    }

    public function test_generation_rate_limit_is_separate_from_gemini(): void
    {
        Queue::fake();
        config(['api.rate_limits.generate' => 1]);

        $this->generate()->assertAccepted();
        $this->generate(['language' => 'de'])->assertTooManyRequests()->assertJsonPath('code', 'rate_limited');
    }

    public function test_analysis_returns_fields_and_creates_nothing(): void
    {
        Http::fake(['*' => Http::response($this->geminiBody(json_encode([
            'is_product_description' => true,
            'original_name' => 'Gaming Controller Stand',
            'sku' => 'GCS-100',
            'category' => 'Gaming Accessories',
            'material' => 'PLA',
            'details' => 'A 3D printed stand.',
        ])))]);

        $before = Product::query()->count();

        $this->client()->postJson('/api/v1/ai/analyze-product', ['prompt' => self::PROMPT])
            ->assertOk()
            ->assertJsonPath('data.original_name', 'Gaming Controller Stand')
            ->assertJsonPath('data.sku', 'GCS-100')
            ->assertJsonPath('data.material', 'PLA');

        $this->assertSame($before, Product::query()->count());
    }

    public function test_analysis_rejects_noise_for_free_and_enforces_quota(): void
    {
        Http::fake();

        $this->client()->postJson('/api/v1/ai/analyze-product', ['prompt' => 'aaaa aaaa aaaa aaaa'])->assertUnprocessable()->assertJsonValidationErrors('prompt');

        config(['services.gemini.user_daily_limit' => 0]);

        $this->client()->postJson('/api/v1/ai/analyze-product', ['prompt' => self::PROMPT])->assertTooManyRequests()->assertJsonPath('code', 'daily_limit_reached');

        Http::assertNothingSent();
    }

    public function test_analysis_shares_the_web_per_minute_limit(): void
    {
        Http::fake();
        config(['services.gemini.per_minute_limit' => 1]);
        RateLimiter::hit(md5('ai-generate'.$this->user->id), 60);

        $this->client()->postJson('/api/v1/ai/analyze-product', ['prompt' => self::PROMPT])->assertTooManyRequests()->assertJsonPath('code', 'rate_limited');

        Http::assertNothingSent();
    }

    public function test_a_retry_with_the_same_key_returns_the_first_result(): void
    {
        Queue::fake();
        $headers = ['Idempotency-Key' => 'retry-1'];

        $first = $this->generate(['language' => 'en', 'tone' => 'friendly'], $headers)->assertAccepted();

        // The body is the same even though the fields arrive in another order.
        $second = $this->generate(['tone' => 'friendly', 'language' => 'en'], $headers)->assertAccepted()->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame($first->json(), $second->json());
        $this->assertSame($first->headers->get('Location'), $second->headers->get('Location'));
        $this->assertSame(1, AiGeneration::query()->count());
        Queue::assertPushed(GenerateQueuedContent::class, 1);
    }

    public function test_a_retry_after_completion_doesnt_spend_tokens_again(): void
    {
        Queue::fake();
        $this->fakeGeminiSuccess();
        $headers = ['Idempotency-Key' => 'retry-2'];

        $id = $this->generate(['language' => 'en'], $headers)->json('data.generation_id');
        $this->runQueuedGeneration($id);

        $this->generate(['language' => 'en'], $headers)->assertAccepted()->assertJsonPath('data.generation_id', $id);

        Http::assertSentCount(1);
    }

    public function test_reusing_a_key_with_a_different_body_is_a_conflict(): void
    {
        Queue::fake();
        $headers = ['Idempotency-Key' => 'retry-3'];

        $this->generate(['language' => 'en'], $headers)->assertAccepted();
        $this->generate(['language' => 'de'], $headers)->assertConflict()->assertJsonPath('code', 'idempotency_key_conflict');

        $this->assertSame(1, AiGeneration::query()->count());
    }

    public function test_a_request_in_progress_blocks_its_retry(): void
    {
        ApiIdempotencyKey::query()->create([
            'user_id' => $this->user->id,
            'operation' => 'POST api/v1/products',
            'key_hash' => hash('sha256', 'busy'),
            'request_hash' => hash('sha256', json_encode(['original_name' => 'A'])),
            'status' => ApiIdempotencyKey::IN_PROGRESS,
            'locked_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        $this->client(['Idempotency-Key' => 'busy'])->postJson('/api/v1/products', ['original_name' => 'A'])
            ->assertConflict()
            ->assertJsonPath('code', 'idempotency_in_progress')
            ->assertHeader('Retry-After');
    }

    public function test_an_abandoned_request_can_be_retried(): void
    {
        ApiIdempotencyKey::query()->create([
            'user_id' => $this->user->id,
            'operation' => 'POST api/v1/products',
            'key_hash' => hash('sha256', 'crashed'),
            'request_hash' => hash('sha256', 'whatever'),
            'status' => ApiIdempotencyKey::IN_PROGRESS,
            'locked_at' => now()->subHour(),
            'expires_at' => now()->addDay(),
        ]);

        $this->client(['Idempotency-Key' => 'crashed'])->postJson('/api/v1/products', ['original_name' => 'A'])->assertCreated();
    }

    public function test_product_creation_is_idempotent(): void
    {
        $headers = ['Idempotency-Key' => 'create-1'];
        $before = Product::query()->count();

        $first = $this->client($headers)->postJson('/api/v1/products', ['original_name' => 'Once'])->assertCreated();
        $second = $this->client($headers)->postJson('/api/v1/products', ['original_name' => 'Once'])->assertCreated()->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame($before + 1, Product::query()->count());
    }

    public function test_a_failed_request_doesnt_consume_its_key(): void
    {
        $headers = ['Idempotency-Key' => 'fix-and-retry'];

        $this->client($headers)->postJson('/api/v1/products', ['sku' => 'x'])->assertUnprocessable();
        $this->assertSame(0, ApiIdempotencyKey::query()->count());

        $this->client($headers)->postJson('/api/v1/products', ['original_name' => 'Fixed'])->assertCreated();
    }

    public function test_keys_are_scoped_to_the_user_and_operation(): void
    {
        Queue::fake();
        $headers = ['Idempotency-Key' => 'shared'];
        $other = $this->tokenFor(User::factory()->create());

        $mine = $this->client($headers)->postJson('/api/v1/products', ['original_name' => 'Same'])->json('data.id');
        $theirs = $this->api($other)->withHeaders($headers)->postJson('/api/v1/products', ['original_name' => 'Same'])->assertCreated()->json('data.id');

        $this->assertNotSame($mine, $theirs);
        $this->generate(['language' => 'en'], $headers)->assertAccepted();
    }

    public function test_keys_expire_and_are_stored_only_as_hashes(): void
    {
        $headers = ['Idempotency-Key' => 'old-key'];

        $first = $this->client($headers)->postJson('/api/v1/products', ['original_name' => 'Hashed'])->json('data.id');

        $raw = DB::table('api_idempotency_keys')->sole();
        $this->assertSame(hash('sha256', 'old-key'), $raw->key_hash);
        $this->assertStringNotContainsString('Hashed', (string) $raw->response);

        $this->travel(25)->hours();

        $second = $this->client($headers)->postJson('/api/v1/products', ['original_name' => 'Hashed'])->assertCreated()->json('data.id');
        $this->assertNotSame($first, $second);

        $this->travel(25)->hours();
        $this->artisan('api:prune')->assertSuccessful();
        $this->assertSame(0, ApiIdempotencyKey::query()->count());
    }

    public function test_an_invalid_key_is_a_400(): void
    {
        $this->client(['Idempotency-Key' => 'has spaces and ~'])->postJson('/api/v1/products', ['original_name' => 'A'])
            ->assertBadRequest()
            ->assertJsonPath('code', 'invalid_idempotency_key');
    }
}
