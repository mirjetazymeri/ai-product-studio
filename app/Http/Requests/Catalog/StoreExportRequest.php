<?php

namespace App\Http\Requests\Catalog;

use App\Enums\ContentLanguage;
use App\Enums\ExportContentScope;
use App\Enums\ProductStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExportRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'format' => ['required', Rule::in(['csv', 'xlsx'])],
            'scope' => ['required', Rule::enum(ExportContentScope::class)],
            'languages' => ['nullable', 'required_unless:scope,product_only', 'array'],
            'languages.*' => [Rule::enum(ContentLanguage::class)],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer'],
            'all_matching' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(ProductStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'languages.required_unless' => 'Choose at least one language.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function selection(): array
    {
        return $this->safe()->only(['product_ids', 'all_matching', 'search', 'status']);
    }
}
