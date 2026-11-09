<?php

namespace App\Http\Controllers\AI;

use App\Enums\AiError;
use App\Http\Controllers\Controller;
use App\Http\Requests\AI\AnalyzeProductRequest;
use App\Services\AI\Exceptions\AiGenerationException;
use App\Services\AI\ProductInformationExtractor;
use Illuminate\Http\JsonResponse;
use Throwable;

class ProductAnalysisController extends Controller
{
    public function store(AnalyzeProductRequest $request, ProductInformationExtractor $extractor): JsonResponse
    {
        try {
            return response()->json([
                'product' => $extractor->extract($request->user(), $request->validated('prompt')),
            ]);
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
