<?php

namespace App\Enums;

/**
 * What an API token may do. Ownership is checked separately on every request.
 */
enum ApiAbility: string
{
    case ProductsRead = 'products:read';
    case ProductsWrite = 'products:write';
    case ContentRead = 'content:read';
    case ContentWrite = 'content:write';
    case AiGenerate = 'ai:generate';
    case AiRead = 'ai:read';
    case ExportsRead = 'exports:read';

    public function label(): string
    {
        return match ($this) {
            self::ProductsRead => 'Read products',
            self::ProductsWrite => 'Create, update and delete products',
            self::ContentRead => 'Read content and run SEO analysis',
            self::ContentWrite => 'Create and update content',
            self::AiGenerate => 'Start AI generation and analysis (uses your AI quota)',
            self::AiRead => 'Read AI generation results',
            self::ExportsRead => 'Create and download exports',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
