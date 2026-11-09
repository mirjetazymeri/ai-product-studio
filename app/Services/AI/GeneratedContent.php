<?php

namespace App\Services\AI;

final readonly class GeneratedContent
{
    /**
     * @param  list<string>  $keywords
     */
    public function __construct(
        public string $title,
        public string $shortDescription,
        public string $longDescription,
        public string $metaTitle,
        public string $metaDescription,
        public string $slug,
        public array $keywords = [],
    ) {}

    /**
     * @param  array<string, mixed>  $fields  validated against ProductContentRules
     */
    public static function fromArray(array $fields): self
    {
        return new self(
            title: $fields['title'],
            shortDescription: $fields['short_description'],
            longDescription: $fields['long_description'],
            metaTitle: $fields['meta_title'],
            metaDescription: $fields['meta_description'],
            slug: $fields['slug'],
            keywords: $fields['keywords'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'short_description' => $this->shortDescription,
            'long_description' => $this->longDescription,
            'meta_title' => $this->metaTitle,
            'meta_description' => $this->metaDescription,
            'slug' => $this->slug,
            'keywords' => $this->keywords,
        ];
    }
}
