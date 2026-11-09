<?php

namespace App\Http\Requests\AI;

use App\Enums\ContentLanguage;
use App\Services\AI\GenerationPreferences;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartBulkGenerationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'product_ids' => ['required', 'array', 'min:1', 'max:'.config('catalog.bulk_generation_max_products')],
            'product_ids.*' => ['integer'],
            'languages' => ['required', 'array', 'min:1'],
            'languages.*' => [Rule::enum(ContentLanguage::class)],
            'submission_token' => ['required', 'uuid'],
            'confirm' => ['accepted'],
            ...GenerationPreferences::rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product_ids.required' => 'Select at least one product.',
            'languages.required' => 'Choose at least one language.',
            'confirm.accepted' => 'Confirm that you want to start this batch.',
            'custom_audience.required_if' => 'Describe your target audience.',
        ];
    }

    public function preferences(): GenerationPreferences
    {
        return GenerationPreferences::fromValidated($this->validated());
    }
}
