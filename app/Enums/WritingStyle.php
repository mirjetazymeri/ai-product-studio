<?php

namespace App\Enums;

enum WritingStyle: string
{
    case Informative = 'informative';
    case Persuasive = 'persuasive';
    case SeoOptimized = 'seo_optimized';
    case Storytelling = 'storytelling';

    public function label(): string
    {
        return match ($this) {
            self::Informative => 'Informative',
            self::Persuasive => 'Persuasive',
            self::SeoOptimized => 'SEO Optimized',
            self::Storytelling => 'Storytelling',
        };
    }
}
