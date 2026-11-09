<?php

namespace App\Enums;

enum ContentLanguage: string
{
    case English = 'en';
    case Albanian = 'sq';
    case German = 'de';

    public function label(): string
    {
        return match ($this) {
            self::English => 'English',
            self::Albanian => 'Albanian',
            self::German => 'German',
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
