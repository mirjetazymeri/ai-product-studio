<?php

namespace App\Http\Controllers\Billing;

use App\Services\Billing\StripeWebhookLedger;
use Illuminate\Http\Request;
use Laravel\Cashier\Http\Controllers\WebhookController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cashier applies the subscription change. This adds duplicate and ordering protection.
 * The signature has been verified by the time this runs.
 */
class StripeWebhookController extends WebhookController
{
    public function __construct(private readonly StripeWebhookLedger $ledger)
    {
        parent::__construct();
    }

    public function handleWebhook(Request $request): Response
    {
        $payload = json_decode($request->getContent(), true);

        abort_unless(is_array($payload) && is_string($payload['id'] ?? null) && is_string($payload['type'] ?? null), 400);

        return $this->ledger->process($payload, fn () => parent::handleWebhook($request));
    }
}
