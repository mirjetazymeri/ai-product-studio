<?php

namespace App\Http\Requests\AI;

use App\Models\Product;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class AnalyzeProductRequest extends FormRequest
{
    private const MIN_DISTINCT_WORDS = 3;

    public function authorize(): bool
    {
        return $this->user()->can('create', Product::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'min:15', 'max:5000', $this->describesSomething(...)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'prompt.required' => 'Describe your product first.',
            'prompt.min' => 'Please add a little more detail about your product.',
            'prompt.max' => 'Please shorten your description to 5,000 characters or fewer.',
        ];
    }

    /**
     * Catches obvious noise for free, before any Gemini quota is spent.
     */
    private function describesSomething(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        preg_match_all('/\p{L}{2,}/u', mb_strtolower($value), $matches);

        if (count(array_unique($matches[0])) < self::MIN_DISTINCT_WORDS) {
            $fail('Please describe your product in a few words, including what it is.');
        }
    }
}
