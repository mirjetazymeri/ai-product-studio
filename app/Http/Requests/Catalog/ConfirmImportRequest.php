<?php

namespace App\Http\Requests\Catalog;

use App\Enums\ImportStrategy;
use App\Services\Catalog\CatalogImporter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConfirmImportRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'strategy' => ['required', Rule::enum(ImportStrategy::class)],
            'update_fields' => ['nullable', 'required_if:strategy,update', 'array'],
            'update_fields.*' => [Rule::in(array_keys(CatalogImporter::UPDATABLE_FIELDS))],
            'confirm_update' => ['accepted_if:strategy,update'],
            'approve_new' => ['accepted_if:strategy,create_new'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'update_fields.required_if' => 'Choose at least one field to update.',
            'confirm_update.accepted_if' => 'Confirm that existing products may be updated.',
            'approve_new.accepted_if' => 'Confirm that matching products may be imported as new.',
        ];
    }

    public function strategy(): ImportStrategy
    {
        return ImportStrategy::from($this->validated('strategy'));
    }

    /**
     * @return list<string>
     */
    public function updateFields(): array
    {
        return $this->strategy() === ImportStrategy::Update ? array_values($this->validated('update_fields') ?? []) : [];
    }
}
