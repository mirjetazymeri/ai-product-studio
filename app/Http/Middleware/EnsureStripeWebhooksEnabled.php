<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cashier skips signature checks when no secret is set. Here, no secret means no webhooks.
 */
class EnsureStripeWebhooksEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('billing.provider') === 'stripe', 404);
        abort_if(blank(config('cashier.webhook.secret')), 503);

        return $next($request);
    }
}
