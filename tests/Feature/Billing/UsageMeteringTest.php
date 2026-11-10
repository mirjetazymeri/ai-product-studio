<?php

namespace Tests\Feature\Billing;

use App\Enums\AiError;
use App\Enums\AiGenerationStatus;
use App\Enums\UsageAction;
use App\Jobs\GenerateQueuedContent;
use App\Models\AiGeneration;
use App\Models\Product;
use App\Models\ProductContent;
use App\Models\UsageEvent;
use App\Models\User;
use App\Services\AI\Exceptions\AiGenerationException;
use App\Services\AI\ProductContentGenerator;
use App\Services\AI\ProductInformationExtractor;
use App\Services\Billing\UsageMeteringService;
use App\Services\WooCommerce\Demo\WooCommerceDemoContentService;
use App\Services\WooCommerce\Demo\WooCommerceDemoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\FakesGemini;
use Tests\Concerns\UsesApiTokens;
use Tests\Concerns\UsesBilling;
use Tests\TestCase;

class UsageMeteringTest extends TestCase
{
    use FakesGemini, RefreshDatabase, UsesApiTokens, UsesBilling;

    private const PROMPT = 'A 3D printed gaming controller stand for PS5 controllers, made from PLA, SKU GCS-100.';

    private User $user;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useRealDefaultPlan();
        $this->user = User::factory()->create();
        $this->product = Product::factory()->for($this->user)->create(['details' => 'Leather.']);
    }

    private function metering(): UsageMeteringService
    {
        return app(UsageMeteringService::class);
    }

    private function fakeGemini(): void
    {
        Http::fake(['*' => Http::response($this->geminiBody())]);
    }

    private function generate()
    {
        return $this->actingAs($this->user)->postJson(route('ai.generate', $this->product), ['language' => 'en']);
    }

    public function test_a_successful_generation_consumes_one_unit_with_full_details(): void
    {
        $this->fakeGemini();

        $this->generate()->assertCreated();

        $event = UsageEvent::query()->sole();
        $generation = AiGeneration::query()->sole();

        $this->assertSame(1, $this->used($this->user));
        $this->assertSame(UsageAction::Generation, $event->action);
        $this->assertSame(UsageEvent::CONSUMED, $event->status);
        $this->assertSame('free', $event->plan);
        $this->assertSame(now('UTC')->format('Y-m'), $event->period);
        $this->assertSame('generation', $event->subject_type);
        $this->assertSame($generation->id, (int) $event->subject_id);
        $this->assertTrue($event->simulated);
    }

    public function test_a_regeneration_is_one_more_unit(): void
    {
        $this->fakeGemini();

        $this->generate()->assertCreated();
        $this->actingAs($this->user)->postJson(route('ai.generate', $this->product), ['language' => 'en', 'regenerate' => true])->assertCreated();

        $this->assertSame(2, $this->used($this->user));
        $this->assertSame(UsageAction::Regeneration, UsageEvent::query()->latest('id')->first()->action);
    }

    public function test_a_failed_generation_uses_nothing_but_still_counts_daily(): void
    {
        Http::fake(['*' => Http::response($this->geminiError(400, 'INVALID_ARGUMENT'), 400)]);

        $this->generate();

        $this->assertSame(0, $this->used($this->user));
        $this->assertSame(UsageEvent::RELEASED, UsageEvent::query()->sole()->status);
        $this->assertSame(AiGenerationStatus::Failed, AiGeneration::query()->sole()->status);
    }

    public function test_the_monthly_allowance_is_enforced_without_calling_gemini(): void
    {
        $this->setPlanLimit('free', 'ai_generations', 1);
        $this->fakeGemini();

        $this->generate()->assertCreated();
        $this->generate()->assertTooManyRequests()->assertJsonPath('code', 'monthly_limit_reached');

        Http::assertSentCount(1);
        $this->assertSame(1, AiGeneration::query()->count());
    }

    public function test_a_higher_plan_raises_the_allowance_immediately(): void
    {
        $this->setPlanLimit('free', 'ai_generations', 1);
        $this->fakeGemini();

        $this->generate()->assertCreated();
        $this->generate()->assertTooManyRequests();

        $this->subscribeTo($this->user, 'starter');

        $this->generate()->assertCreated();
    }

    public function test_the_allowance_resets_each_utc_month_without_deleting_history(): void
    {
        $this->setPlanLimit('free', 'ai_generations', 1);
        $this->fakeGemini();

        $this->travelTo('2026-11-30 23:59:00');
        $this->generate()->assertCreated();
        $this->generate()->assertTooManyRequests();

        $this->travelTo('2026-12-01 00:00:30');
        RateLimiter::clear(md5('ai-generate'.$this->user->id));

        $this->assertSame(0, $this->used($this->user));
        $this->generate()->assertCreated();
        $this->assertSame(2, UsageEvent::query()->count());
        $this->assertEqualsCanonicalizing(['2026-11', '2026-12'], UsageEvent::query()->pluck('period')->all());
    }

    public function test_the_daily_limit_still_applies_when_the_monthly_allowance_has_room(): void
    {
        config(['services.gemini.user_daily_limit' => 0]);
        $this->fakeGemini();

        $this->generate()->assertTooManyRequests()->assertJsonPath('code', 'daily_limit_reached');

        $this->assertSame(0, $this->used($this->user));
        Http::assertNothingSent();
    }

    public function test_the_per_minute_limit_still_applies(): void
    {
        config(['services.gemini.per_minute_limit' => 1]);
        RateLimiter::hit(md5('ai-generate'.$this->user->id), 60);
        $this->fakeGemini();

        $this->generate()->assertTooManyRequests()->assertJsonPath('code', 'rate_limited');

        $this->assertSame(0, $this->used($this->user));
    }

    public function test_competing_reservations_cant_exceed_the_allowance(): void
    {
        $this->setPlanLimit('free', 'ai_generations', 2);
        $accepted = 0;

        foreach (range(1, 5) as $attempt) {
            try {
                $this->metering()->reserve($this->user, UsageAction::Generation, "race:{$attempt}");
                $accepted++;
            } catch (AiGenerationException $exception) {
                $this->assertSame(AiError::MonthlyLimitReached, $exception->error);
            }
        }

        $this->assertSame(2, $accepted);
        $this->assertSame(2, $this->used($this->user));
        $this->assertSame(2, UsageEvent::query()->count());
    }

    public function test_the_same_key_never_counts_twice(): void
    {
        $first = $this->metering()->reserve($this->user, UsageAction::Generation, 'same');
        $second = $this->metering()->reserve($this->user, UsageAction::Generation, 'same');

        $this->assertTrue($first->is($second));
        $this->assertSame(1, $this->used($this->user));
    }

    public function test_settling_twice_has_no_extra_effect(): void
    {
        $this->metering()->reserve($this->user, UsageAction::Generation, 'a');
        $this->metering()->release($this->user->id, 'a');
        $this->metering()->release($this->user->id, 'a');

        $this->assertSame(0, $this->used($this->user));

        $this->metering()->reserve($this->user, UsageAction::Generation, 'b');
        $this->metering()->consume($this->user->id, 'b');
        $this->metering()->release($this->user->id, 'b');

        $this->assertSame(1, $this->used($this->user));
    }

    public function test_a_reservation_is_released_against_its_own_month(): void
    {
        $this->travelTo('2026-11-30 23:59:00');
        $this->metering()->reserve($this->user, UsageAction::Generation, 'late');

        $this->travelTo('2026-12-01 00:01:00');
        $this->metering()->reserve($this->user, UsageAction::Generation, 'new');
        $this->metering()->release($this->user->id, 'late');

        $this->assertSame(1, $this->used($this->user));
    }

    public function test_analysis_uses_one_unit_and_failure_uses_none(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push($this->geminiBody(json_encode(['is_product_description' => true, 'original_name' => 'Stand', 'sku' => null, 'category' => 'Gaming', 'material' => null, 'details' => 'A stand.'])))
            ->push($this->geminiError(400, 'INVALID_ARGUMENT'), 400)]);

        app(ProductInformationExtractor::class)->extract($this->user, self::PROMPT);

        $this->assertSame(1, $this->used($this->user));

        try {
            app(ProductInformationExtractor::class)->extract($this->user, self::PROMPT);
            $this->fail('The request should have failed.');
        } catch (AiGenerationException) {
            $this->assertSame(1, $this->used($this->user));
        }
    }

    public function test_analysis_is_refused_at_the_allowance(): void
    {
        $this->setPlanLimit('free', 'ai_generations', 0);
        Http::fake();

        $this->actingAs($this->user)->postJson(route('ai.products.analyze'), ['prompt' => self::PROMPT])
            ->assertTooManyRequests()
            ->assertJsonPath('code', 'monthly_limit_reached');

        Http::assertNothingSent();
    }

    public function test_queued_work_holds_a_unit_until_it_settles(): void
    {
        Queue::fake();
        $this->subscribeTo($this->user, 'pro');
        $token = $this->tokenFor($this->user);
        $this->fakeGemini();

        $id = $this->api($token)->postJson("/api/v1/products/{$this->product->id}/generate", ['language' => 'en'])->assertAccepted()->json('data.generation_id');

        $this->assertSame(UsageEvent::RESERVED, UsageEvent::query()->sole()->status);
        $this->assertSame(1, $this->used($this->user));

        app(ProductContentGenerator::class)->processQueued($id);
        app(ProductContentGenerator::class)->processQueued($id);

        $this->assertSame(UsageEvent::CONSUMED, UsageEvent::query()->sole()->status);
        $this->assertSame(1, $this->used($this->user));
    }

    public function test_a_lost_job_gives_its_unit_back(): void
    {
        Queue::fake();
        $this->subscribeTo($this->user, 'pro');
        $id = $this->api($this->tokenFor($this->user))->postJson("/api/v1/products/{$this->product->id}/generate", ['language' => 'en'])->json('data.generation_id');

        (new GenerateQueuedContent($id))->failed(new \RuntimeException('worker died'));

        $this->assertSame(0, $this->used($this->user));
        $this->assertSame(UsageEvent::RELEASED, UsageEvent::query()->sole()->status);
    }

    public function test_an_idempotent_retry_isnt_counted_again(): void
    {
        Queue::fake();
        $this->subscribeTo($this->user, 'pro');
        $token = $this->tokenFor($this->user);

        foreach (range(1, 3) as $attempt) {
            $this->api($token)->withHeaders(['Idempotency-Key' => 'retry'])->postJson("/api/v1/products/{$this->product->id}/generate", ['language' => 'en'])->assertAccepted();
        }

        $this->assertSame(1, $this->used($this->user));
        $this->assertSame(1, UsageEvent::query()->count());
    }

    public function test_stale_work_releases_its_reservation(): void
    {
        $generation = $this->user->aiGenerations()->create(['product_id' => $this->product->id, 'product_name' => 'x', 'provider' => 'gemini', 'model' => 'm', 'language' => 'en', 'status' => AiGenerationStatus::Pending]);
        $generation->forceFill(['created_at' => now()->subHour()])->save();
        $this->metering()->reserve($this->user, UsageAction::Generation, UsageMeteringService::generationKey($generation->id), 'generation', $generation->id);

        app(ProductContentGenerator::class)->closeStaleGenerations($this->user);

        $this->assertSame(0, $this->used($this->user));
    }

    public function test_sample_output_uses_no_allowance(): void
    {
        Http::fake();
        $store = app(WooCommerceDemoService::class)->activate($this->user);
        $this->actingAs($this->user)->post(route('integrations.imports.store', $store), ['language' => 'en']);
        $this->post(route('integrations.imports.confirm', $this->user->catalogTransfers()->latest('id')->first()), ['import_new' => '1']);

        $this->assertGreaterThan(0, app(WooCommerceDemoContentService::class)->generateSamples($store->refresh()));

        $this->assertSame(0, $this->used($this->user));
        $this->assertSame(0, UsageEvent::query()->count());
    }

    public function test_bulk_items_each_use_one_unit_and_stop_at_the_allowance(): void
    {
        $this->subscribeTo($this->user, 'starter');
        $this->setPlanLimit('starter', 'ai_generations', 2);
        $second = Product::factory()->for($this->user)->create(['details' => 'x']);
        $third = Product::factory()->for($this->user)->create(['details' => 'x']);
        $this->fakeGemini();

        $this->actingAs($this->user)->post(route('ai.bulk.store'), [
            'product_ids' => [$this->product->id, $second->id, $third->id],
            'languages' => ['en'],
            'submission_token' => '11111111-1111-4111-8111-111111111111',
            'confirm' => '1',
        ])->assertSessionHasErrors('product_ids');

        $this->assertSame(0, $this->user->bulkGenerationBatches()->count());

        $this->actingAs($this->user)->post(route('ai.bulk.store'), [
            'product_ids' => [$this->product->id, $second->id],
            'languages' => ['en'],
            'submission_token' => '22222222-2222-4222-8222-222222222222',
            'confirm' => '1',
        ])->assertRedirect();

        $this->assertSame(2, $this->used($this->user));
        $this->assertSame([UsageAction::BulkItem], UsageEvent::query()->get()->pluck('action')->unique()->values()->all());
    }

    public function test_generation_never_overwrites_saved_content(): void
    {
        $this->fakeGemini();
        $content = ProductContent::factory()->for($this->product)->create(['language' => 'en', 'title' => 'Mine']);

        $this->generate()->assertCreated();

        $this->assertSame('Mine', $content->fresh()->title);
    }

    public function test_token_totals_are_untouched_by_metering(): void
    {
        $this->fakeGemini();

        $this->generate()->assertCreated();

        $this->assertSame(220, AiGeneration::query()->sole()->total_tokens);
    }
}
