<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Free = 'free';
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Canceling = 'canceling';
    case Canceled = 'canceled';
    case Expired = 'expired';
    case Incomplete = 'incomplete';

    public function label(): string
    {
        return match ($this) {
            self::Free => 'Free',
            self::Trialing => 'Trialing',
            self::Active => 'Active',
            self::PastDue => 'Past Due',
            self::Canceling => 'Canceling',
            self::Canceled => 'Canceled',
            self::Expired => 'Expired',
            self::Incomplete => 'Incomplete',
        };
    }
}
