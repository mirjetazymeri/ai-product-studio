<?php

namespace App\Enums;

enum ContentTone: string
{
    case Professional = 'professional';
    case Friendly = 'friendly';
    case Luxury = 'luxury';
    case Minimalist = 'minimalist';
    case Playful = 'playful';
    case Technical = 'technical';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
