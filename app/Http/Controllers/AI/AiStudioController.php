<?php

namespace App\Http\Controllers\AI;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AiStudioController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        $validated = $request->validate(['product' => ['nullable', 'integer']]);

        $product = null;

        if (isset($validated['product'])) {
            $product = Product::with('contents')->findOrFail($validated['product']);

            Gate::authorize('update', $product);

            if ($product->isDemo()) {
                return to_route('integrations.demo.show', $product->demo_store_id);
            }
        }

        return view('ai-studio', [
            'products' => $request->user()->products()->orderBy('original_name')->get(['id', 'original_name', 'sku']),
            'product' => $product,
        ]);
    }
}
