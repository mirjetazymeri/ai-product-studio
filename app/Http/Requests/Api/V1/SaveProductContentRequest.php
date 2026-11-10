<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ResolvesOwnedProduct;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class SaveProductContentRequest extends FormRequest
{
    use ResolvesOwnedProduct;

    private const FIELDS = ['title', 'short_description', 'long_description', 'meta_title', 'meta_description', 'slug', 'keywords'];

    protected function prepareForValidation(): void
    {
        $source = collect([$this->input('slug'), $this->input('title')])->first(fn ($value) => is_string($value) && filled($value));

        $this->merge(['slug' => $source === null ? null : (Str::slug($source, '-', (string) $this->route('language')) ?: null)]);
    }

    /**
     * The limits match the product form and the database columns.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $others = implode(',', array_diff(self::FIELDS, ['title', 'slug']));

        return [
            'title' => ['nullable', 'required_without_all:'.$others, 'string', 'max:255'],
            'short_description' => ['nullable', 'string', 'max:1000'],
            'long_description' => ['nullable', 'string', 'max:20000'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'slug' => ['nullable', 'string', 'max:255'],
            'keywords' => ['nullable', 'array', 'max:30'],
            'keywords.*' => ['string', 'max:64'],
            'confirm_overwrite' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * PUT replaces the whole record, so a field left out is cleared.
     *
     * @return array<string, mixed>
     */
    public function contentFields(): array
    {
        return [...array_fill_keys(self::FIELDS, null), ...$this->safe()->only(self::FIELDS)];
    }
}
