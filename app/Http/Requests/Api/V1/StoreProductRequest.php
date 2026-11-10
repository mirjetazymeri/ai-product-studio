<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Products\SaveProductRequest;
use App\Models\Product;
use App\Services\ProductExternalReferenceService;
use Closure;
use Illuminate\Support\Arr;

class StoreProductRequest extends SaveProductRequest
{
    private const PRODUCT_FIELDS = ['original_name', 'sku', 'category', 'material', 'details', 'status'];

    public function authorize(): bool
    {
        return $this->user()->can('create', Product::class);
    }

    /**
     * The same field rules as the web form. Content has its own endpoint.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = Arr::only(parent::rules(), self::PRODUCT_FIELDS);

        $rules['status'][0] = 'sometimes';

        return [
            ...$rules,
            'external_reference' => ['sometimes', 'array:source,external_id'],
            'external_reference.source' => ['required_with:external_reference', 'string', 'regex:'.ProductExternalReferenceService::SOURCE_FORMAT, $this->isNotReserved(...)],
            'external_reference.external_id' => ['required_with:external_reference', 'string', 'max:191'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['original_name.required' => 'The product name is required.'];
    }

    /**
     * @return array<string, mixed>
     */
    public function productAttributes(): array
    {
        return [...['status' => 'draft'], ...$this->safe()->only(self::PRODUCT_FIELDS)];
    }

    /**
     * @return array{source: string, externalId: string}|null
     */
    public function externalReference(): ?array
    {
        $reference = $this->validated('external_reference');

        return $reference === null ? null : ['source' => $reference['source'], 'externalId' => $reference['external_id']];
    }

    private function isNotReserved(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && ProductExternalReferenceService::isReserved($value)) {
            $fail('This external source name is reserved for store integrations.');
        }
    }
}
