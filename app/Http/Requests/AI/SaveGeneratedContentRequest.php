<?php

namespace App\Http\Requests\AI;

use App\Services\AI\ProductContentRules;
use Illuminate\Foundation\Http\FormRequest;

class SaveGeneratedContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('save', $this->route('generation'));
    }

    protected function prepareForValidation(): void
    {
        $this->replace(ProductContentRules::normalise($this->all(), $this->route('generation')->language));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...ProductContentRules::rules(),
            'confirm_overwrite' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function contentFields(): array
    {
        return $this->safe()->except('confirm_overwrite');
    }

    public function confirmsOverwrite(): bool
    {
        return $this->boolean('confirm_overwrite');
    }
}
