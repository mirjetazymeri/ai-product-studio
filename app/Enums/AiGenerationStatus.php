<?php

namespace App\Enums;

enum AiGenerationStatus: string
{
    /** Accepted from the API and waiting for a worker. It already counts towards quota. */
    case Queued = 'queued';
    case Pending = 'pending';

    /** Claimed from the queue by a worker. */
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Pending, self::Processing => 'In progress',
            self::Succeeded => 'Succeeded',
            self::Failed => 'Failed',
        };
    }

    public function apiValue(): string
    {
        return $this === self::Pending ? 'processing' : $this->value;
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Queued, self::Pending, self::Processing], true);
    }
}
