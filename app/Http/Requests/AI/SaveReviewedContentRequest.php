<?php

namespace App\Http\Requests\AI;

use App\Services\AI\ProductContentRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveReviewedContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('review', $this->route('item'));
    }

    protected function prepareForValidation(): void
    {
        $this->replace(ProductContentRules::normalise($this->all(), $this->route('item')->language));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...ProductContentRules::rules(),
            'action' => ['required', Rule::in(['save', 'approve'])],
            'confirm_overwrite' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function contentFields(): array
    {
        return $this->safe()->except(['action', 'confirm_overwrite']);
    }

    public function approves(): bool
    {
        return $this->validated('action') === 'approve';
    }

    public function confirmsOverwrite(): bool
    {
        return $this->boolean('confirm_overwrite');
    }
}
