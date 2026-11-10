<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ResolvesOwnedProduct;
use App\Http\Requests\Products\SaveProductRequest;
use Illuminate\Support\Arr;

class UpdateProductRequest extends SaveProductRequest
{
    use ResolvesOwnedProduct;

    private const PRODUCT_FIELDS = ['original_name', 'sku', 'category', 'material', 'details', 'status'];

    /**
     * The web form's rules, applying only to the fields that were sent.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_map(
            fn (array $rules) => ['sometimes', ...$rules],
            Arr::only(parent::rules(), self::PRODUCT_FIELDS),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function productAttributes(): array
    {
        return $this->safe()->only(self::PRODUCT_FIELDS);
    }
}
