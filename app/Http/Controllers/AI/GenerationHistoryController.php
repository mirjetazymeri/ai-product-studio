<?php

namespace App\Http\Controllers\AI;

use App\Http\Controllers\Controller;
use App\Services\AI\GenerationQuota;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GenerationHistoryController extends Controller
{
    public function __invoke(Request $request, GenerationQuota $quota): View
    {
        $user = $request->user();

        return view('generation-history', [
            'generations' => $user->aiGenerations()->with('product:id')->latest()->latest('id')->paginate(15),
            'stats' => [
                'remaining' => $quota->remainingFor($user),
                'tokensToday' => (int) $user->aiGenerations()->where('created_at', '>=', today())->sum('total_tokens'),
                'tokensTotal' => (int) $user->aiGenerations()->sum('total_tokens'),
            ],
        ]);
    }
}
