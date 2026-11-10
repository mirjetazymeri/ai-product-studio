<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class AnalyzeSeoRequest extends FormRequest
{
    private const FIELDS = ['title', 'short_description', 'long_description', 'meta_title', 'meta_description', 'slug', 'keywords'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'product_name' => ['nullable', 'string', 'max:255'],
            'title' => ['nullable', 'required_without_all:'.implode(',', array_diff(self::FIELDS, ['title'])), 'string', 'max:255'],
            'short_description' => ['nullable', 'string', 'max:1000'],
            'long_description' => ['nullable', 'string', 'max:20000'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'slug' => ['nullable', 'string', 'max:255'],
            'keywords' => ['nullable', 'array', 'max:30'],
            'keywords.*' => ['string', 'max:64'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function contentFields(): array
    {
        return $this->safe()->only(self::FIELDS);
    }
}
