<?php

namespace App\Services\Billing;

final readonly class SubscriptionPlan
{
    /**
     * @param  array<string, int>  $limits
     * @param  list<string>  $features
     * @param  array<string, string>  $comparison
     */
    public function __construct(
        public string $key,
        public string $name,
        public int $rank,
        public int $priceCents,
        public ?string $stripePrice,
        public string $description,
        public array $limits,
        public array $features,
        public array $comparison,
    ) {}

    public function isPaid(): bool
    {
        return $this->priceCents > 0;
    }

    public function limit(string $key): int
    {
        return (int) ($this->limits[$key] ?? 0);
    }

    public function displayPrice(): string
    {
        return '€'.rtrim(rtrim(number_format($this->priceCents / 100, 2), '0'), '.');
    }
}
