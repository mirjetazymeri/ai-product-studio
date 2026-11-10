<?php

namespace App\Http\Controllers\Billing;

use App\Enums\BillingMode;
use App\Http\Controllers\Controller;
use App\Services\Billing\BillingManager;
use App\Services\Billing\BillingOverviewService;
use App\Services\Billing\BillingResult;
use App\Services\Billing\Exceptions\BillingException;
use App\Services\Billing\SubscriptionPlan;
use App\Services\Billing\SubscriptionPlanService;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BillingController extends Controller
{
    public function __construct(
        private readonly BillingManager $billing,
        private readonly SubscriptionPlanService $plans,
    ) {}

    public function index(Request $request, BillingOverviewService $overview): View
    {
        $provider = $this->billing->provider();

        return view('billing.index', [
            'overview' => $overview->for($request->user()),
            'plans' => $this->plans->all(),
            'records' => $provider->records($request->user()),
            'unavailableReason' => $provider->unavailableReason(),
            'demoEnabled' => $this->billing->demoEnabled(),
            'hasPortal' => $provider->mode() !== BillingMode::Demo && $provider->unavailableReason() === null && $request->user()->hasStripeId(),
        ]);
    }

    public function confirm(Request $request, string $plan): View|RedirectResponse
    {
        $target = $this->plan($plan);
        $provider = $this->billing->provider();
        $state = $provider->state($request->user());

        if ($target->key === $state->plan->key && $state->status->value !== 'canceling') {
            return to_route('billing.index')->with('status', "You're already on the {$target->name} plan.");
        }

        return view('billing.confirm', [
            'target' => $target,
            'current' => $state->plan,
            'mode' => $provider->mode(),
            'unavailableReason' => $provider->unavailableReason(),
        ]);
    }

    /**
     * The plan comes from the URL and is looked up on the server. Any submitted price is ignored.
     */
    public function change(Request $request, string $plan): RedirectResponse
    {
        $target = $this->plan($plan);

        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => 'Confirm the plan change first.']);

        return $this->run(fn () => $target->isPaid()
            ? $this->billing->provider()->changePlan($request->user(), $target)
            : $this->billing->provider()->cancel($request->user()));
    }

    public function cancel(Request $request): RedirectResponse
    {
        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => 'Confirm that you want to cancel.']);

        return $this->run(fn () => $this->billing->provider()->cancel($request->user()));
    }

    public function resume(Request $request): RedirectResponse
    {
        return $this->run(fn () => $this->billing->provider()->resume($request->user()));
    }

    public function portal(Request $request): RedirectResponse
    {
        try {
            $url = $this->billing->provider()->portalUrl($request->user(), route('billing.index'));
        } catch (BillingException $exception) {
            return to_route('billing.index')->withErrors(['billing' => $exception->getMessage()]);
        }

        abort_if($url === null, 404);

        return redirect()->away($url);
    }

    public function record(Request $request, string $record): View|RedirectResponse
    {
        $found = $this->billing->provider()->findRecord($request->user(), $record);

        abort_if($found === null, 404);

        return $found->url !== null ? redirect()->away($found->url) : view('billing.record', ['record' => $found]);
    }

    /**
     * Arriving here proves nothing. Access only changes when a signed webhook says so.
     */
    public function checkoutReturn(Request $request): RedirectResponse
    {
        return to_route('billing.index')->with('status', $request->query('result') === 'success'
            ? 'Thanks. Your plan updates as soon as Stripe confirms the payment.'
            : 'Checkout was canceled. Nothing was changed.');
    }

    private function plan(string $key): SubscriptionPlan
    {
        return $this->plans->find($key) ?? abort(404);
    }

    /**
     * @param  Closure(): BillingResult  $action
     */
    private function run(Closure $action): RedirectResponse
    {
        try {
            $result = $action();
        } catch (BillingException $exception) {
            return to_route('billing.index')->withErrors(['billing' => $exception->getMessage()]);
        }

        return $result->redirectUrl !== null
            ? redirect()->away($result->redirectUrl)
            : to_route('billing.index')->with('status', $result->message);
    }
}
