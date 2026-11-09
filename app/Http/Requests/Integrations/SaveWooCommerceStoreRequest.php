<?php

namespace App\Http\Requests\Integrations;

use Illuminate\Foundation\Http\FormRequest;

class SaveWooCommerceStoreRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isEditing = $this->route('store') !== null;

        // When editing, blank keeps the saved credentials. Both must change together.
        $credential = $isEditing
            ? ['nullable', 'string', 'max:255', 'required_with:consumer_key,consumer_secret']
            : ['required', 'string', 'max:255'];

        return [
            'name' => ['required', 'string', 'max:120'],
            'url' => ['required', 'string', 'max:255'],
            'consumer_key' => $credential,
            'consumer_secret' => $credential,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => 'store name', 'url' => 'store URL'];
    }
}
