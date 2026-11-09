<?php

namespace App\Http\Controllers;

use App\Enums\AiGenerationStatus;
use App\Enums\ProductStatus;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        return view('dashboard', [
            'stats' => [
                'total' => $user->products()->count(),
                'draft' => $user->products()->withStatus(ProductStatus::Draft)->count(),
                'published' => $user->products()->withStatus(ProductStatus::Published)->count(),
                'generations' => $user->aiGenerations()->where('status', AiGenerationStatus::Succeeded->value)->count(),
            ],
            'recentProducts' => $user->products()->sortedByCreation()->limit(5)->get(),
        ]);
    }
}
