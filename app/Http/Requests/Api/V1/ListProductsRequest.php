<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ContentLanguage;
use App\Enums\ProductStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListProductsRequest extends FormRequest
{
    private const SORTS = ['created_at' => 'created_at', 'updated_at' => 'updated_at', 'name' => 'original_name'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $sorts = array_keys(self::SORTS);

        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(ProductStatus::class)],
            'language' => ['nullable', Rule::enum(ContentLanguage::class)],
            'sku' => ['nullable', 'string', 'max:100'],
            'external_source' => ['nullable', 'required_with:external_id', 'string', 'max:50'],
            'external_id' => ['nullable', 'required_with:external_source', 'string', 'max:191'],
            'sort' => ['nullable', Rule::in([...$sorts, ...array_map(fn (string $sort) => "-{$sort}", $sorts)])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('api.pagination.max')],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{string, string}
     */
    public function sorting(): array
    {
        $sort = (string) ($this->validated('sort') ?? '-created_at');

        return [self::SORTS[ltrim($sort, '-')], str_starts_with($sort, '-') ? 'desc' : 'asc'];
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? config('api.pagination.default'));
    }
}
