<?php

namespace App\Http\Requests\Catalog;

use App\Enums\ContentLanguage;
use App\Services\Catalog\ColumnMapper;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveColumnMappingRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mapping' => ['required', 'array'],
            'mapping.*' => ['nullable', Rule::in(array_keys(ColumnMapper::destinations()))],

            // Names reserved for real platform connections must not be used for a file label.
            'source' => ['nullable', 'string', 'max:50', 'regex:/^[a-z0-9][a-z0-9_-]*$/i', Rule::notIn(['woocommerce', 'shopify'])],
            'default_language' => ['required', Rule::enum(ContentLanguage::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'source.regex' => 'Use only letters, numbers, hyphens and underscores.',
            'source.not_in' => 'That name is reserved for a future store connection. Please choose another.',
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $chosen = array_values(array_filter((array) $this->input('mapping', []), 'is_string'));

            foreach (ColumnMapper::REQUIRED_FIELDS as $field) {
                if (! in_array($field, $chosen, true)) {
                    $validator->errors()->add('mapping', 'Choose which column contains the '.ColumnMapper::destinations()[$field].'.');
                }
            }

            foreach (array_unique(array_diff_assoc($chosen, array_unique($chosen))) as $duplicate) {
                $label = ColumnMapper::destinations()[$duplicate] ?? $duplicate;
                $validator->errors()->add('mapping', "{$label} is mapped to more than one column.");
            }
        }];
    }

    /**
     * @return array<int, string>
     */
    public function columnMap(): array
    {
        return array_filter($this->validated('mapping'), fn ($field) => filled($field));
    }

    public function source(): string
    {
        return mb_strtolower($this->validated('source') ?? 'file');
    }

    public function defaultLanguage(): ContentLanguage
    {
        return ContentLanguage::from($this->validated('default_language'));
    }
}
