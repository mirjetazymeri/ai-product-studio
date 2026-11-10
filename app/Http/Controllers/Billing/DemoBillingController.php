<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Services\Billing\BillingManager;
use App\Services\Billing\DemoBillingProvider;
use App\Services\Billing\Exceptions\BillingException;
use App\Services\Billing\UsageMeteringService;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Simulation controls. Missing entirely unless the server has demo billing switched on.
 */
class DemoBillingController extends Controller
{
    private DemoBillingProvider $demo;

    public function __construct(BillingManager $billing, private readonly UsageMeteringService $usage)
    {
        abort_unless($billing->demoEnabled(), 404);

        $this->demo = $billing->demo();
    }

    public function paymentFailure(Request $request): RedirectResponse
    {
        return $this->run(fn () => $this->demo->simulatePaymentFailure($request->user()), 'Simulation: the payment failed. The plan stays usable during the grace period.');
    }

    public function paymentRecovery(Request $request): RedirectResponse
    {
        return $this->run(fn () => $this->demo->simulatePaymentRecovery($request->user()), 'Simulation: the retry succeeded. No payment was taken.');
    }

    public function expire(Request $request): RedirectResponse
    {
        return $this->run(fn () => $this->demo->simulateExpiration($request->user()), 'Simulation: the subscription expired. Your data is unchanged.');
    }

    public function restoreFree(Request $request): RedirectResponse
    {
        return $this->run(fn () => $this->demo->restoreFree($request->user()), 'Simulation: you\'re back on the Free plan.');
    }

    public function useAllowance(Request $request): RedirectResponse
    {
        return $this->run(fn () => $this->usage->addSimulatedUsage($request->user(), $this->usage->remaining($request->user())), 'Simulation: this month\'s AI allowance is now used up. No AI request was made.');
    }

    public function reset(Request $request): RedirectResponse
    {
        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => 'Confirm that you want to reset demo billing.']);

        return $this->run(function () use ($request) {
            $this->demo->reset($request->user());
            $this->usage->clearSimulatedUsage($request->user());
        }, 'Demo billing was reset. Real AI usage, products and content weren\'t changed.');
    }

    private function run(Closure $action, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (BillingException $exception) {
            return to_route('billing.index')->withErrors(['billing' => $exception->getMessage()]);
        }

        return to_route('billing.index')->with('status', $message);
    }
}
