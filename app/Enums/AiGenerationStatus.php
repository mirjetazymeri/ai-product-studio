<?php

namespace App\Enums;

enum AiGenerationStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'In progress',
            self::Succeeded => 'Succeeded',
            self::Failed => 'Failed',
        };
    }
}
