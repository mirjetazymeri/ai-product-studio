<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Models\Product;

trait ResolvesOwnedProduct
{
    private ?Product $ownedProduct = null;

    /**
     * Another user's product is not found, even before the body is validated.
     */
    public function ownedProduct(): Product
    {
        return $this->ownedProduct ??= $this->user()->products()->whereKey($this->route('product'))->firstOrFail();
    }

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->ownedProduct());
    }
}
