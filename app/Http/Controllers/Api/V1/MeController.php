<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Billing\BillingOverviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends ApiController
{
    public function __invoke(Request $request, BillingOverviewService $billing): JsonResponse
    {
        $user = $request->user();
        $token = $user->currentAccessToken();
        $overview = $billing->for($user);

        return response()->json(['data' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'token' => [
                'name' => $token->name,
                'abilities' => array_values($token->abilities),
                'expires_at' => $token->expires_at?->toIso8601String(),
            ],
            // Plan and usage only. Billing identifiers are never part of this.
            'subscription' => [
                'plan' => $overview['plan']->key,
                'status' => $overview['state']->status->value,
                'ai_generations_used' => $overview['ai']['used'],
                'ai_generations_limit' => $overview['ai']['limit'],
                'products_used' => $overview['products']['used'],
                'products_limit' => $overview['products']['limit'],
                'usage_resets_at' => $overview['period']['resets_at']->toIso8601String(),
            ],
        ]]);
    }
}
