<?php

namespace App\Enums;

enum ImportStrategy: string
{
    case Skip = 'skip';
    case Update = 'update';
    case CreateNew = 'create_new';

    public function label(): string
    {
        return match ($this) {
            self::Skip => 'Skip existing products',
            self::Update => 'Update selected fields',
            self::CreateNew => 'Import as new where safe',
        };
    }
}
