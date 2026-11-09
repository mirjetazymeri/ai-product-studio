<?php

namespace App\Http\Requests\Products;

use App\Enums\ProductStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductFilterRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(ProductStatus::class)],
            'sort' => ['nullable', Rule::in(['newest', 'oldest'])],
        ];
    }

    public function search(): ?string
    {
        return $this->validated('search');
    }

    public function status(): ?ProductStatus
    {
        return ProductStatus::tryFrom((string) $this->validated('status'));
    }

    public function sortDirection(): string
    {
        return $this->validated('sort') === 'oldest' ? 'asc' : 'desc';
    }
}
