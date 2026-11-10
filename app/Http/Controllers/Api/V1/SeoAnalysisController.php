<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\AnalyzeSeoRequest;
use App\Services\AI\Quality\SeoAnalyzer;
use Illuminate\Http\JsonResponse;

class SeoAnalysisController extends ApiController
{
    /**
     * Rule-based, so this never calls Gemini or uses quota.
     */
    public function __invoke(AnalyzeSeoRequest $request, SeoAnalyzer $analyzer): JsonResponse
    {
        $result = $analyzer->analyze($request->contentFields(), (string) $request->validated('product_name'));

        return response()->json(['data' => [
            'score' => $result['score'],
            'field_feedback' => $result['checks'],
            'warnings' => $result['warnings'],
            'suggestions' => $result['suggestions'],
        ]]);
    }
}
