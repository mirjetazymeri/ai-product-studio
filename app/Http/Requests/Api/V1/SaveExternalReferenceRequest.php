<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ResolvesOwnedProduct;
use Illuminate\Foundation\Http\FormRequest;

class SaveExternalReferenceRequest extends FormRequest
{
    use ResolvesOwnedProduct;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['external_id' => ['required', 'string', 'max:191']];
    }
}
