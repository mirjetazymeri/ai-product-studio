<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ContentLanguage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateExportRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'format' => ['required', Rule::in(['csv', 'xlsx'])],
            'languages' => ['sometimes', 'array', 'min:1'],
            'languages.*' => [Rule::enum(ContentLanguage::class)],
            'approved_only' => ['sometimes', 'boolean'],
            'product_ids' => ['sometimes', 'array', 'min:1', 'max:'.config('api.export_max_product_ids')],
            'product_ids.*' => ['integer', 'min:1'],
        ];
    }

    /**
     * Starts from the caller's products later, so other users' IDs simply match nothing.
     *
     * @return array<string, mixed>
     */
    public function selection(): array
    {
        return ['product_ids' => array_values(array_unique($this->validated('product_ids') ?? []))];
    }

    public function approvedOnly(): bool
    {
        return $this->boolean('approved_only', true);
    }
}
