<?php

namespace App\Services\Billing;

use App\Enums\BillingMode;
use App\Enums\SubscriptionStatus;
use App\Models\User;
use App\Services\Billing\Exceptions\BillingException;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Laravel\Cashier\Exceptions\InvalidInvoice;
use Laravel\Cashier\Invoice;
use Laravel\Cashier\Subscription;
use Stripe\Exception\ApiErrorException;
use Throwable;

/**
 * Stripe through Laravel Cashier. Card details only ever go to Stripe's hosted pages.
 *
 * Access comes from local subscription rows, which Stripe-signed webhooks and Stripe's
 * own API responses write. Returning from Checkout changes nothing.
 */
class StripeBillingProvider implements BillingProvider
{
    public const SUBSCRIPTION_TYPE = 'default';

    public function __construct(private readonly SubscriptionPlanService $plans) {}

    public function mode(): BillingMode
    {
        return config('billing.mode') === 'live' ? BillingMode::StripeLive : BillingMode::StripeTest;
    }

    /**
     * Checks configuration without revealing any value.
     */
    public function unavailableReason(): ?string
    {
        $secret = (string) config('cashier.secret');
        $billingMode = config('billing.mode');
        $configured = 'Stripe billing isn\'t fully configured on this server.';

        if (! in_array($billingMode, ['test', 'live'], true) || blank(config('cashier.key')) || $secret === '' || blank(config('cashier.webhook.secret'))) {
            return $configured;
        }

        if ($billingMode === 'live' && ! config('billing.allow_live')) {
            return 'Live billing isn\'t enabled on this server.';
        }

        // Test keys in live mode, or live keys in test mode, would charge the wrong way.
        if (preg_match("/^[sr]k_{$billingMode}_/", $secret) !== 1) {
            return $configured;
        }

        foreach ($this->plans->all() as $plan) {
            if ($plan->isPaid() && $plan->stripePrice === null) {
                return $configured;
            }
        }

        return null;
    }

    public function state(User $user): SubscriptionState
    {
        $default = $this->plans->default();
        $subscription = $this->subscription($user);

        if ($subscription === null) {
            return new SubscriptionState($default, null, SubscriptionStatus::Free);
        }

        $plan = $this->plans->forStripePrice($subscription->stripe_price);

        if ($plan === null) {
            Log::warning('A Stripe subscription uses an unrecognised price.', ['subscription_id' => $subscription->id]);

            return new SubscriptionState($default, null, SubscriptionStatus::Incomplete);
        }

        $reference = $subscription->stripe_id;

        if ($subscription->ends_at !== null && $subscription->ends_at->isFuture() && in_array($subscription->stripe_status, ['active', 'trialing'], true)) {
            return new SubscriptionState($plan, $plan, SubscriptionStatus::Canceling, endsAt: $subscription->ends_at, reference: $reference);
        }

        if ($subscription->ends_at !== null && $subscription->ends_at->isPast()) {
            return new SubscriptionState($default, $plan, SubscriptionStatus::Expired, endsAt: $subscription->ends_at, reference: $reference);
        }

        return match ($subscription->stripe_status) {
            'active' => new SubscriptionState($plan, $plan, SubscriptionStatus::Active, reference: $reference),
            'trialing' => new SubscriptionState($plan, $plan, SubscriptionStatus::Trialing, endsAt: $subscription->trial_ends_at, reference: $reference),
            'past_due' => $this->pastDue($subscription, $plan, $default),
            'canceled', 'unpaid' => new SubscriptionState($default, $plan, SubscriptionStatus::Canceled, endsAt: $subscription->ends_at, reference: $reference),
            default => new SubscriptionState($default, $plan, SubscriptionStatus::Incomplete, reference: $reference),
        };
    }

    public function changePlan(User $user, SubscriptionPlan $plan): BillingResult
    {
        $this->ensureReady();

        $priceId = $plan->stripePrice ?? throw new BillingException('This plan can\'t be purchased.');
        $subscription = $this->subscription($user);

        return $this->attempt(function () use ($user, $priceId, $plan, $subscription) {
            if ($subscription !== null && in_array($subscription->stripe_status, ['active', 'trialing', 'past_due'], true) && ! $subscription->ended()) {
                $subscription->swap($priceId);

                return new BillingResult("Your subscription was changed to {$plan->name}.");
            }

            $session = $user->newSubscription(self::SUBSCRIPTION_TYPE, $priceId)->checkout([
                'success_url' => route('billing.checkout.return', ['result' => 'success']),
                'cancel_url' => route('billing.checkout.return', ['result' => 'cancelled']),
            ])->asStripeCheckoutSession();

            return new BillingResult('Continue to Stripe to finish.', $session->url);
        });
    }

    public function cancel(User $user): BillingResult
    {
        $this->ensureReady();

        $subscription = $this->subscription($user) ?? throw new BillingException('There is no active subscription to cancel.');

        return $this->attempt(function () use ($subscription) {
            $subscription->cancel();

            return new BillingResult('Your subscription will end at the close of the billing period.');
        });
    }

    public function resume(User $user): BillingResult
    {
        $this->ensureReady();

        $subscription = $this->subscription($user);

        if ($subscription === null || ! $subscription->onGracePeriod()) {
            throw new BillingException('This subscription can\'t be resumed.');
        }

        return $this->attempt(function () use ($subscription) {
            $subscription->resume();

            return new BillingResult('Your subscription will continue.');
        });
    }

    public function records(User $user): array
    {
        if (! $user->hasStripeId() || $this->unavailableReason() !== null) {
            return [];
        }

        try {
            return $user->invoices()->map($this->toRecord(...))->values()->all();
        } catch (ApiErrorException $exception) {
            Log::warning('Stripe invoices could not be listed.', ['user_id' => $user->id, 'error' => $exception::class]);

            return [];
        }
    }

    /**
     * Cashier refuses an invoice that belongs to a different customer.
     */
    public function findRecord(User $user, string $id): ?BillingRecord
    {
        if (! $user->hasStripeId() || $this->unavailableReason() !== null) {
            return null;
        }

        try {
            $invoice = $user->findInvoice($id);
        } catch (InvalidInvoice|ApiErrorException) {
            return null;
        }

        return $invoice === null ? null : $this->toRecord($invoice);
    }

    public function portalUrl(User $user, string $returnUrl): ?string
    {
        if (! $user->hasStripeId() || $this->unavailableReason() !== null) {
            return null;
        }

        return $this->attempt(fn () => $user->billingPortalUrl($returnUrl));
    }

    private function subscription(User $user): ?Subscription
    {
        return $user->subscriptions()->where('type', self::SUBSCRIPTION_TYPE)->latest('id')->first();
    }

    private function pastDue(Subscription $subscription, SubscriptionPlan $plan, SubscriptionPlan $default): SubscriptionState
    {
        $since = $subscription->past_due_at === null ? null : Carbon::parse($subscription->past_due_at);
        $graceEndsAt = ($since ?? $subscription->updated_at)->copy()->addDays((int) config('billing.past_due_grace_days'));

        return new SubscriptionState($graceEndsAt->isFuture() ? $plan : $default, $plan, SubscriptionStatus::PastDue, graceEndsAt: $graceEndsAt, reference: $subscription->stripe_id);
    }

    private function ensureReady(): void
    {
        if (($reason = $this->unavailableReason()) !== null) {
            throw new BillingException($reason);
        }
    }

    /**
     * Stripe's error text can carry account details, so it never reaches the user or the log.
     *
     * @template T
     *
     * @param  Closure(): T  $action
     * @return T
     */
    private function attempt(Closure $action): mixed
    {
        try {
            return $action();
        } catch (IncompletePayment) {
            throw new BillingException('Your bank needs you to confirm this payment. Open the billing portal to finish.');
        } catch (BillingException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('A Stripe billing action failed.', ['error' => $exception::class]);

            throw new BillingException('Stripe couldn\'t complete this. Nothing was changed. Please try again.');
        }
    }

    private function toRecord(Invoice $invoice): BillingRecord
    {
        $stripe = $invoice->asStripeInvoice();

        return new BillingRecord(
            id: $stripe->id,
            reference: (string) ($stripe->number ?? $stripe->id),
            date: Carbon::createFromTimestampUTC($stripe->created),
            description: 'Subscription invoice',
            amountCents: (int) $stripe->total,
            currency: (string) $stripe->currency,
            status: (string) $stripe->status,
            simulated: false,
            url: $stripe->hosted_invoice_url,
        );
    }
}
