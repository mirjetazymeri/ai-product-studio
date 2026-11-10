<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

abstract class ApiController extends Controller
{
    /**
     * Starts from the caller's own catalog, so another user's product is simply not
     * found. That also avoids confirming which IDs exist.
     */
    protected function ownedProduct(Request $request, int|string $id, string $policyAbility = 'view'): Product
    {
        $product = $request->user()->products()->whereKey($id)->firstOrFail();

        Gate::authorize($policyAbility, $product);

        return $product;
    }
}
