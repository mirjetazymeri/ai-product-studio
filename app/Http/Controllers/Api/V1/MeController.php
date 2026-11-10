<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        return response()->json(['data' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'token' => [
                'name' => $token->name,
                'abilities' => array_values($token->abilities),
                'expires_at' => $token->expires_at?->toIso8601String(),
            ],
        ]]);
    }
}
