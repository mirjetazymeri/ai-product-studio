<?php

namespace App\Enums;

enum BulkItemStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Generated = 'generated';
    case Failed = 'failed';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
