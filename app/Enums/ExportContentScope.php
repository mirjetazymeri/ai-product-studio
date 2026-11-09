<?php

namespace App\Enums;

enum ExportContentScope: string
{
    case ProductOnly = 'product_only';
    case Generated = 'generated';
    case Approved = 'approved';
    case All = 'all';

    public function label(): string
    {
        return match ($this) {
            self::ProductOnly => 'Product information only',
            self::Generated => 'Product information with AI-generated content',
            self::Approved => 'Approved content only',
            self::All => 'All existing content',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ProductOnly => 'One row per product, with no language content.',
            self::Generated => 'AI content that was saved, plus AI drafts still waiting for review.',
            self::Approved => 'Content saved to the product: written by hand, imported, or approved AI content.',
            self::All => 'All saved content, plus AI drafts still waiting for review.',
        };
    }

    public function includesContent(): bool
    {
        return $this !== self::ProductOnly;
    }

    public function includesDrafts(): bool
    {
        return $this === self::Generated || $this === self::All;
    }
}
