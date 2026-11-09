<?php

namespace App\Http\Controllers\AI;

use App\Enums\AiError;
use App\Http\Controllers\Controller;
use App\Http\Requests\AI\GenerateContentRequest;
use App\Http\Requests\AI\ReviewGeneratedContentRequest;
use App\Http\Requests\AI\SaveGeneratedContentRequest;
use App\Models\AiGeneration;
use App\Models\Product;
use App\Services\AI\Exceptions\AiGenerationException;
use App\Services\AI\GenerationQuota;
use App\Services\AI\ProductContentGenerator;
use App\Services\AI\Quality\ContentReviewer;
use Closure;
use Illuminate\Http\JsonResponse;
use Throwable;

class GenerationController extends Controller
{
    public function __construct(
        private readonly ProductContentGenerator $generator,
        private readonly GenerationQuota $quota,
        private readonly ContentReviewer $reviewer,
    ) {}

    public function store(GenerateContentRequest $request, Product $product): JsonResponse
    {
        // Demo products may only reach Gemini through their explicit opt-in form.
        if ($product->isDemo()) {
            return response()->json(['message' => 'Demo products use Sample AI Output. Real Gemini requests are started from the demo product page.', 'code' => 'demo_product', 'retry_after' => null], 422);
        }

        return $this->respond(function () use ($request, $product) {
            $preferences = $request->preferences();

            $generation = $this->generator->generate(
                $request->user(),
                $product,
                $request->language(),
                $request->isRegeneration(),
                $preferences,
            );

            $existing = $product->contents()->where('language', $generation->language->value)->first();

            return response()->json([
                'content' => $generation->generated_content,
                'existing' => $existing?->only(array_keys($generation->generated_content)),
                'language' => $generation->language->label(),
                'preferences' => $preferences->summary(),
                'review' => $this->reviewer->review($product, $generation->generated_content),
                'review_url' => route('ai.generations.review', $generation),
                'save_url' => route('ai.generations.save', $generation),
                'usage' => [
                    'input' => $generation->input_tokens,
                    'output' => $generation->output_tokens,
                    'total' => $generation->total_tokens,
                ],
                'remaining_today' => $this->quota->remainingFor($request->user()),
            ], 201);
        });
    }

    /**
     * Refreshes the feedback as the preview is edited. This never calls Gemini.
     */
    public function review(ReviewGeneratedContentRequest $request, AiGeneration $generation): JsonResponse
    {
        return $this->respond(function () use ($request, $generation) {
            $product = $generation->product ?? throw new AiGenerationException(AiError::ProductMissing);

            return response()->json(['review' => $this->reviewer->review($product, $request->validated())]);
        });
    }

    public function save(SaveGeneratedContentRequest $request, AiGeneration $generation): JsonResponse
    {
        return $this->respond(function () use ($request, $generation) {
            $product = $this->generator->save($generation, $request->contentFields(), $request->confirmsOverwrite());

            session()->flash('status', 'AI content saved.');

            return response()->json(['redirect' => route('products.show', $product)]);
        });
    }

    /**
     * @param  Closure(): JsonResponse  $action
     */
    private function respond(Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (AiGenerationException $exception) {
            return $this->error($exception->error, $exception->retryAfter);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error(AiError::Unexpected);
        }
    }

    private function error(AiError $error, ?int $retryAfter = null): JsonResponse
    {
        return response()->json([
            'message' => $error->message(),
            'code' => $error->value,
            'retry_after' => $retryAfter,
        ], $error->httpStatus());
    }
}
