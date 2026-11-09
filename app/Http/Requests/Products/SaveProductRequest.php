<?php

namespace App\Http\Requests\Products;

use App\Enums\ContentLanguage;
use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SaveProductRequest extends FormRequest
{
    private const CONTENT_FIELDS = [
        'title' => 'title',
        'short_description' => 'short description',
        'long_description' => 'long description',
        'meta_title' => 'meta title',
        'meta_description' => 'meta description',
        'slug' => 'URL slug',
        'keywords' => 'keywords',
    ];

    public function authorize(): bool
    {
        $product = $this->route('product');

        return $product instanceof Product
            ? $this->user()->can('update', $product)
            : $this->user()->can('create', Product::class);
    }

    protected function prepareForValidation(): void
    {
        $contents = $this->input('contents');

        if (! is_array($contents)) {
            return;
        }

        foreach ($contents as $code => $content) {
            if (! is_array($content)) {
                continue;
            }

            $contents[$code]['slug'] = $this->normaliseSlug($content, (string) $code);
            $contents[$code]['keywords'] = $this->normaliseKeywords($content['keywords'] ?? null);
        }

        $this->merge(['contents' => $contents]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'original_name' => ['required', 'string', 'max:255'],
            'sku' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('products', 'sku')
                    ->where('user_id', $this->user()->id)
                    ->ignore($this->route('product')),
            ],
            'category' => ['nullable', 'string', 'max:120'],
            'material' => ['nullable', 'string', 'max:120'],
            'details' => ['nullable', 'string', 'max:10000'],
            'status' => ['required', Rule::enum(ProductStatus::class)],

            'contents' => ['nullable', 'array:'.implode(',', ContentLanguage::values())],
            'contents.*' => ['array:'.implode(',', array_keys(self::CONTENT_FIELDS))],
            'contents.*.title' => ['nullable', 'string', 'max:255'],
            'contents.*.short_description' => ['nullable', 'string', 'max:1000'],
            'contents.*.long_description' => ['nullable', 'string', 'max:20000'],
            'contents.*.meta_title' => ['nullable', 'string', 'max:255'],
            'contents.*.meta_description' => ['nullable', 'string', 'max:500'],
            'contents.*.slug' => ['nullable', 'string', 'max:255'],
            'contents.*.keywords' => ['nullable', 'array', 'max:30'],
            'contents.*.keywords.*' => ['string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = ['original_name' => 'product name', 'sku' => 'SKU'];

        foreach (ContentLanguage::cases() as $language) {
            foreach (self::CONTENT_FIELDS as $field => $label) {
                $attributes["contents.{$language->value}.{$field}"] = "{$language->label()} {$label}";
            }

            $attributes["contents.{$language->value}.keywords.*"] = "{$language->label()} keyword";
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    public function productAttributes(): array
    {
        return $this->safe()->except('contents');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function contents(): array
    {
        return $this->validated('contents') ?? [];
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function normaliseSlug(array $content, string $code): ?string
    {
        $source = collect([$content['slug'] ?? null, $content['title'] ?? null])
            ->first(fn ($value) => is_string($value) && filled($value));

        return $source === null ? null : (Str::slug($source, '-', $code) ?: null);
    }

    /**
     * Keywords are typed as one comma-separated field and stored as a JSON list.
     */
    private function normaliseKeywords(mixed $keywords): mixed
    {
        if (! is_string($keywords)) {
            return $keywords;
        }

        return collect(explode(',', $keywords))
            ->map(fn (string $keyword) => trim($keyword))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
