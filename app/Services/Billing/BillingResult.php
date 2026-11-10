<?php

namespace App\Services\Billing;

final readonly class BillingResult
{
    public function __construct(public string $message, public ?string $redirectUrl = null) {}
}
