<?php

namespace Tests\Feature\AI;

use App\Enums\AiError;
use App\Services\AI\Exceptions\AiGenerationException;
use App\Services\AI\GeminiService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesGemini;
use Tests\TestCase;

class GeminiServiceTest extends TestCase
{
    use FakesGemini;

    private function generate(): mixed
    {
        return app(GeminiService::class)->generateJson('system', 'prompt', ['type' => 'object']);
    }

    private function assertFailsWith(AiError $expected): AiGenerationException
    {
        try {
            $this->generate();
        } catch (AiGenerationException $exception) {
            $this->assertSame($expected, $exception->error);

            return $exception;
        }

        $this->fail("Expected the {$expected->value} error.");
    }

    public function test_it_sends_an_authenticated_structured_request_for_the_configured_model(): void
    {
        Http::fake(['*' => Http::response($this->geminiBody())]);

        $this->generate();

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://gemini.test/v1beta/models/test-gemini-model:generateContent'
                && $request->hasHeader('x-goog-api-key', 'test-gemini-key')
                && $request['generationConfig']['responseMimeType'] === 'application/json'
                && $request['generationConfig']['responseJsonSchema'] === ['type' => 'object']
                && $request['systemInstruction']['parts'][0]['text'] === 'system'
                && $request['contents'][0]['parts'][0]['text'] === 'prompt';
        });
    }

    public function test_it_parses_json_and_reports_token_usage(): void
    {
        Http::fake(['*' => Http::response($this->geminiBody())]);

        $result = $this->generate();

        $this->assertSame('Leather Tote Bag', $result->data['title']);
        $this->assertSame(120, $result->usage->input);
        $this->assertSame(100, $result->usage->output);
        $this->assertSame(220, $result->usage->total);
    }

    public function test_missing_token_usage_is_left_empty_rather_than_invented(): void
    {
        $body = $this->geminiBody();
        unset($body['usageMetadata']);
        Http::fake(['*' => Http::response($body)]);

        $usage = $this->generate()->usage;

        $this->assertNull($usage->input);
        $this->assertNull($usage->output);
        $this->assertNull($usage->total);
    }

    public function test_thought_parts_are_not_treated_as_output(): void
    {
        $body = $this->geminiBody();
        array_unshift($body['candidates'][0]['content']['parts'], ['text' => 'thinking...', 'thought' => true]);
        Http::fake(['*' => Http::response($body)]);

        $this->assertSame('Leather Tote Bag', $this->generate()->data['title']);
    }

    public function test_malformed_json_is_rejected(): void
    {
        Http::fake(['*' => Http::response($this->geminiBody('{"title": "cut off'))]);

        $this->assertFailsWith(AiError::InvalidResponse);
    }

    public function test_a_json_list_is_rejected(): void
    {
        Http::fake(['*' => Http::response($this->geminiBody('["not", "an", "object"]'))]);

        $this->assertFailsWith(AiError::InvalidResponse);
    }

    public function test_a_truncated_response_is_rejected_and_keeps_its_usage(): void
    {
        Http::fake(['*' => Http::response($this->geminiBody([], 'MAX_TOKENS'))]);

        $this->assertSame(220, $this->assertFailsWith(AiError::InvalidResponse)->usage->total);
    }

    public function test_an_empty_response_is_rejected(): void
    {
        Http::fake(['*' => Http::response(['candidates' => []])]);

        $this->assertFailsWith(AiError::EmptyResponse);
    }

    public function test_blank_text_is_rejected(): void
    {
        Http::fake(['*' => Http::response($this->geminiBody('   '))]);

        $this->assertFailsWith(AiError::EmptyResponse);
    }

    public function test_a_blocked_prompt_is_reported(): void
    {
        Http::fake(['*' => Http::response(['promptFeedback' => ['blockReason' => 'SAFETY']])]);

        $this->assertFailsWith(AiError::BlockedResponse);
    }

    public function test_a_filtered_response_is_reported(): void
    {
        Http::fake(['*' => Http::response($this->geminiBody([], 'SAFETY'))]);

        $this->assertFailsWith(AiError::BlockedResponse);
    }

    public function test_a_missing_api_key_stops_before_any_request(): void
    {
        Http::fake();
        config(['services.gemini.api_key' => null]);

        $this->assertFailsWith(AiError::MissingApiKey);

        Http::assertNothingSent();
    }

    public function test_a_missing_model_stops_before_any_request(): void
    {
        Http::fake();
        config(['services.gemini.model' => '']);

        $this->assertFailsWith(AiError::MissingModel);

        Http::assertNothingSent();
    }

    /**
     * @return array<string, array{int, string, array<int, mixed>, AiError}>
     */
    public static function finalErrors(): array
    {
        $invalidKey = [['reason' => 'API_KEY_INVALID']];

        return [
            'invalid key' => [400, 'INVALID_ARGUMENT', $invalidKey, AiError::InvalidApiKey],
            'forbidden' => [403, 'PERMISSION_DENIED', [], AiError::InvalidApiKey],
            'unknown model' => [404, 'NOT_FOUND', [], AiError::ModelUnavailable],
            'quota' => [429, 'RESOURCE_EXHAUSTED', [], AiError::QuotaExceeded],
            'bad request' => [400, 'INVALID_ARGUMENT', [], AiError::ProviderError],
        ];
    }

    #[DataProvider('finalErrors')]
    public function test_non_transient_errors_are_never_retried(int $status, string $apiStatus, array $details, AiError $expected): void
    {
        Http::fake(['*' => Http::response($this->geminiError($status, $apiStatus, details: $details), $status)]);

        $this->assertFailsWith($expected);

        Http::assertSentCount(1);
    }

    public function test_a_quota_error_exposes_googles_retry_delay(): void
    {
        Http::fake(['*' => Http::response($this->geminiError(429, 'RESOURCE_EXHAUSTED', details: [['retryDelay' => '34s']]), 429)]);

        $this->assertSame(34, $this->assertFailsWith(AiError::QuotaExceeded)->retryAfter);
    }

    public function test_a_timeout_is_reported_without_retrying(): void
    {
        Http::fake(['*' => Http::failedConnection('cURL error 28: Operation timed out')]);

        $this->assertFailsWith(AiError::Timeout);

        Http::assertSentCount(1);
    }

    public function test_a_network_failure_is_retried_within_the_limit(): void
    {
        Sleep::fake();
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            return Http::failedConnection('cURL error 6: Could not resolve host');
        });

        $this->assertFailsWith(AiError::NetworkError);

        $this->assertSame(2, $attempts);
    }

    public function test_a_transient_server_error_is_retried_and_can_recover(): void
    {
        Sleep::fake();
        Http::fake(['*' => Http::sequence()
            ->push($this->geminiError(503, 'UNAVAILABLE'), 503)
            ->push($this->geminiBody())]);

        $this->assertSame('Leather Tote Bag', $this->generate()->data['title']);

        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
    }

    public function test_retries_stop_at_the_configured_limit(): void
    {
        Sleep::fake();
        config(['services.gemini.max_retries' => 2]);
        Http::fake(['*' => Http::response($this->geminiError(503, 'UNAVAILABLE'), 503)]);

        $this->assertFailsWith(AiError::ProviderUnavailable);

        Http::assertSentCount(3);
    }

    public function test_the_api_key_is_never_logged(): void
    {
        Log::spy();
        Http::fake(['*' => Http::response($this->geminiError(400, 'INVALID_ARGUMENT', 'Bad key test-gemini-key'), 400)]);

        $this->assertFailsWith(AiError::ProviderError);

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) {
            $this->assertStringNotContainsString('test-gemini-key', json_encode($context));

            return true;
        });
    }

    public function test_the_model_check_makes_no_generation_request(): void
    {
        Http::fake(['*' => Http::response(['name' => 'models/test-gemini-model', 'supportedGenerationMethods' => ['generateContent']])]);

        $this->artisan('ai:gemini-check')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->method() === 'GET' && ! str_contains($request->url(), 'generateContent'));
    }

    public function test_the_model_check_reports_an_unavailable_model(): void
    {
        Http::fake(['*' => Http::response($this->geminiError(404, 'NOT_FOUND'), 404)]);

        $this->artisan('ai:gemini-check')->assertFailed();
    }
}
