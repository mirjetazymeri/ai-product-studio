<?php

namespace App\Http\Requests\AI;

use App\Enums\ContentLanguage;
use App\Services\AI\GenerationPreferences;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('product'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'language' => ['required', Rule::enum(ContentLanguage::class)],
            'regenerate' => ['sometimes', 'boolean'],
            ...GenerationPreferences::rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'custom_audience.required_if' => 'Describe your target audience.',
        ];
    }

    public function language(): ContentLanguage
    {
        return ContentLanguage::from($this->validated('language'));
    }

    public function isRegeneration(): bool
    {
        return $this->boolean('regenerate');
    }

    public function preferences(): GenerationPreferences
    {
        return GenerationPreferences::fromValidated($this->validated());
    }
}
