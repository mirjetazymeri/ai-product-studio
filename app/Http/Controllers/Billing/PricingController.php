<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Services\Billing\BillingManager;
use App\Services\Billing\PlanEntitlementService;
use App\Services\Billing\SubscriptionPlanService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PricingController extends Controller
{
    public function __invoke(Request $request, SubscriptionPlanService $plans, PlanEntitlementService $entitlements, BillingManager $billing): View
    {
        return view($request->user() === null ? 'billing.pricing-public' : 'billing.pricing', [
            'plans' => $plans->all(),
            'currentPlan' => $request->user() === null ? null : $entitlements->plan($request->user()),
            'rows' => config('billing.comparison_rows'),
            'mode' => $billing->provider()->mode(),
        ]);
    }
}
