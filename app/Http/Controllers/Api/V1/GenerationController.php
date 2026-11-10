<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AiError;
use App\Exceptions\ApiException;
use App\Http\Requests\Api\V1\GenerateProductContentRequest;
use App\Http\Resources\Api\V1\GenerationResource;
use App\Services\AI\Exceptions\AiGenerationException;
use App\Services\AI\ProductContentGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class GenerationController extends ApiController
{
    public function __construct(private readonly ProductContentGenerator $generator) {}

    public function store(GenerateProductContentRequest $request): JsonResponse
    {
        $product = $request->ownedProduct();

        try {
            $generation = $this->generator->enqueue($request->user(), $product, $request->language(), $request->isRegeneration(), $request->preferences());
        } catch (AiGenerationException $exception) {
            if ($exception->error !== AiError::DuplicateRequest) {
                throw $exception;
            }

            throw new ApiException(409, $exception->error->value, 'A generation for this product and language is already running.', [
                'generation_id' => $this->generator->activeGeneration($request->user(), $product, $request->language())?->id,
            ]);
        }

        return response()->json(['data' => [
            'generation_id' => $generation->id,
            'status' => $generation->status->apiValue(),
            'product_id' => $generation->product_id,
            'language' => $generation->language->value,
        ]], 202)->header('Location', route('api.v1.generations.show', $generation));
    }

    public function show(Request $request, string $generation): GenerationResource
    {
        $this->generator->closeStaleGenerations($request->user());

        $model = $request->user()->aiGenerations()->with('reviewItem:id,ai_generation_id,review_status')->findOrFail($generation);

        Gate::authorize('view', $model);

        return new GenerationResource($model);
    }
}
