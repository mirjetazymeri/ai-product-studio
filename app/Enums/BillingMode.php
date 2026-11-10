<?php

namespace App\Enums;

enum BillingMode: string
{
    case Demo = 'demo';
    case StripeTest = 'test';
    case StripeLive = 'live';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Demo => 'Demo Billing',
            self::StripeTest => 'Stripe Test Mode',
            self::StripeLive => 'Stripe Live Mode',
            self::Disabled => 'Billing Unavailable',
        };
    }
}
