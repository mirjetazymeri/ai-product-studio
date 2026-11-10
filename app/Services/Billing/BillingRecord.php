<?php

namespace App\Services\Billing;

use Illuminate\Support\Carbon;

/**
 * One line of billing history, from either provider.
 */
final readonly class BillingRecord
{
    public function __construct(
        public string $id,
        public string $reference,
        public Carbon $date,
        public string $description,
        public int $amountCents,
        public string $currency,
        public string $status,
        public bool $simulated,
        public ?string $url = null,
    ) {}

    public function displayAmount(): string
    {
        return strtoupper($this->currency).' '.number_format($this->amountCents / 100, 2);
    }
}
