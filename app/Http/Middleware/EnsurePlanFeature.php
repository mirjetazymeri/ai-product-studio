<?php

namespace App\Http\Middleware;

use App\Services\Billing\PlanEntitlementService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlanFeature
{
    public function __construct(private readonly PlanEntitlementService $entitlements) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $this->entitlements->ensure($request->user(), $feature);

        return $next($request);
    }
}
