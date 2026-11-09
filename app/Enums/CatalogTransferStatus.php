<?php

namespace App\Enums;

enum CatalogTransferStatus: string
{
    case Uploaded = 'uploaded';
    case Validated = 'validated';
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case CompletedWithErrors = 'completed_with_errors';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'Needs mapping',
            self::Validated => 'Ready to confirm',
            self::Queued => 'Queued',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::CompletedWithErrors => 'Completed with errors',
            self::Failed => 'Failed',
        };
    }

    public function isRunning(): bool
    {
        return $this === self::Queued || $this === self::Processing;
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::CompletedWithErrors, self::Failed], true);
    }
}
