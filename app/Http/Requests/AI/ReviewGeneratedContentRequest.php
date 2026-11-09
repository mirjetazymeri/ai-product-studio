<?php

namespace App\Http\Requests\AI;

use Illuminate\Foundation\Http\FormRequest;

class ReviewGeneratedContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('review', $this->route('generation'));
    }

    /**
     * Fields may be blank or unfinished here, because this runs while the user is typing.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:1000'],
            'short_description' => ['nullable', 'string', 'max:5000'],
            'long_description' => ['nullable', 'string', 'max:40000'],
            'meta_title' => ['nullable', 'string', 'max:1000'],
            'meta_description' => ['nullable', 'string', 'max:2000'],
            'slug' => ['nullable', 'string', 'max:1000'],
            'keywords' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
