<?php

namespace App\Http\Middleware;

use App\Models\CatalogTransfer;
use App\Models\WooCommerceStore;
use App\Models\WooCommerceSyncRun;
use App\Services\Billing\PlanEntitlementService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * New work with a real store needs the plan. The simulated demo store is open to everyone,
 * and viewing or disconnecting an existing store stays possible on any plan.
 */
class EnsureStorePlan
{
    public function __construct(private readonly PlanEntitlementService $entitlements) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->store($request)?->isDemo() !== true) {
            $this->entitlements->ensure($request->user(), 'integrations.woocommerce');
        }

        return $next($request);
    }

    private function store(Request $request): ?WooCommerceStore
    {
        $store = $request->route('store');
        $run = $request->route('run');
        $transfer = $request->route('transfer');

        return match (true) {
            $store instanceof WooCommerceStore => $store,
            $run instanceof WooCommerceSyncRun => $run->store,
            $transfer instanceof CatalogTransfer => WooCommerceStore::query()->find($transfer->options['store_id'] ?? 0),
            default => null,
        };
    }
}
