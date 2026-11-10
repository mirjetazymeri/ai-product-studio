<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\AI\AnalyzeProductRequest;
use App\Services\AI\ProductInformationExtractor;
use Illuminate\Http\JsonResponse;

class ProductAnalysisController extends ApiController
{
    /**
     * Reads the description into product fields. No product is created.
     */
    public function __invoke(AnalyzeProductRequest $request, ProductInformationExtractor $extractor): JsonResponse
    {
        return response()->json(['data' => $extractor->extract($request->user(), $request->validated('prompt'))]);
    }
}
