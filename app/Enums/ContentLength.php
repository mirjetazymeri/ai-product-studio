<?php

namespace App\Enums;

enum ContentLength: string
{
    case Short = 'short';
    case Standard = 'standard';
    case Detailed = 'detailed';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
