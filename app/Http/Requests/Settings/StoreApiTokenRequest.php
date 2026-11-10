<?php

namespace App\Http\Requests\Settings;

use App\Enums\ApiAbility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class StoreApiTokenRequest extends FormRequest
{
    protected $errorBag = 'apiToken';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', 'distinct', Rule::enum(ApiAbility::class)],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['abilities.required' => 'Choose at least one permission.'];
    }

    /**
     * The token works through the whole of the chosen day.
     */
    public function expiresAt(): ?Carbon
    {
        return $this->date('expires_at')?->endOfDay();
    }
}
