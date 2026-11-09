<?php

namespace App\Enums;

enum TargetAudience: string
{
    case GeneralConsumers = 'general_consumers';
    case Gamers = 'gamers';
    case Professionals = 'professionals';
    case Students = 'students';
    case Parents = 'parents';
    case GiftBuyers = 'gift_buyers';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::GeneralConsumers => 'General Consumers',
            self::Gamers => 'Gamers',
            self::Professionals => 'Professionals',
            self::Students => 'Students',
            self::Parents => 'Parents',
            self::GiftBuyers => 'Gift Buyers',
            self::Custom => 'Other (describe)',
        };
    }
}
