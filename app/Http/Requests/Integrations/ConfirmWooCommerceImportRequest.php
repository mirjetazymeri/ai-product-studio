<?php

namespace App\Http\Requests\Integrations;

use App\Services\WooCommerce\WooCommerceProductImporter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConfirmWooCommerceImportRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'import_new' => ['sometimes', 'boolean'],
            'refresh_linked' => ['sometimes', 'boolean'],
            'refresh_fields' => ['nullable', 'array', 'required_if_accepted:refresh_linked'],
            'refresh_fields.*' => [Rule::in(array_keys(WooCommerceProductImporter::REFRESHABLE_FIELDS))],
            'confirm_refresh' => ['accepted_if:refresh_linked,1'],
            'decisions' => ['nullable', 'array'],
            'decisions.*' => [Rule::in(['link', 'new', 'skip'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'refresh_fields.required_if_accepted' => 'Choose at least one field to refresh.',
            'confirm_refresh.accepted_if' => 'Confirm that linked products may be refreshed.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function importOptions(): array
    {
        return [
            'import_new' => $this->boolean('import_new'),
            'refresh_linked' => $this->boolean('refresh_linked'),
            'refresh_fields' => $this->boolean('refresh_linked') ? array_values($this->validated('refresh_fields') ?? []) : [],
        ];
    }
}
