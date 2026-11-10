<?php

namespace App\Services\Billing;

use App\Enums\BillingMode;
use App\Enums\SubscriptionStatus;
use App\Models\DemoBillingSubscription;
use App\Models\DemoBillingTransaction;
use App\Models\User;
use App\Services\Billing\Exceptions\BillingException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Simulates a subscription using two local tables. It has no payment code and makes
 * no network calls. BillingManager only uses it when demo billing is allowed.
 */
class DemoBillingProvider implements BillingProvider
{
    public const SUBSCRIPTION_PREFIX = 'DEMO-SUB-';

    public const TRANSACTION_PREFIX = 'DEMO-TXN-';

    public function __construct(private readonly SubscriptionPlanService $plans) {}

    public function mode(): BillingMode
    {
        return BillingMode::Demo;
    }

    public function unavailableReason(): ?string
    {
        return null;
    }

    public function state(User $user): SubscriptionState
    {
        $subscription = DemoBillingSubscription::query()->where('user_id', $user->id)->first();
        $default = $this->plans->default();
        $plan = $subscription === null ? null : $this->plans->find($subscription->plan);

        if ($subscription === null || $plan === null || ! $plan->isPaid() || $subscription->status === SubscriptionStatus::Free) {
            return new SubscriptionState($default, null, SubscriptionStatus::Free);
        }

        return match ($subscription->status) {
            SubscriptionStatus::Active, SubscriptionStatus::Trialing => new SubscriptionState($plan, $plan, $subscription->status, renewsAt: $this->nextRenewal($subscription), reference: $subscription->reference),
            SubscriptionStatus::Canceling => $this->canceling($subscription, $plan, $default),
            SubscriptionStatus::PastDue => $this->pastDue($subscription, $plan, $default),
            default => new SubscriptionState($default, $plan, $subscription->status, endsAt: $subscription->ends_at, reference: $subscription->reference),
        };
    }

    public function changePlan(User $user, SubscriptionPlan $plan): BillingResult
    {
        DB::transaction(function () use ($user, $plan) {
            $this->lock($user);

            DemoBillingSubscription::query()->updateOrCreate(['user_id' => $user->id], [
                'reference' => $this->existingReference($user) ?? self::SUBSCRIPTION_PREFIX.Str::upper(Str::random(12)),
                'plan' => $plan->key,
                'status' => SubscriptionStatus::Active,
                'current_period_start' => now(),
                'current_period_end' => now()->addMonthNoOverflow(),
                'past_due_at' => null,
                'ends_at' => null,
            ]);

            $this->record($user, 'plan_change', $plan->key, $plan->priceCents, 'simulated', "Simulated change to {$plan->name}. No payment was taken.");
        });

        return new BillingResult("Simulation: you're now on the {$plan->name} plan. No payment was taken.");
    }

    public function cancel(User $user): BillingResult
    {
        DB::transaction(function () use ($user) {
            $this->lock($user);

            $subscription = $this->cancellable($user);
            $endsAt = $this->nextRenewal($subscription);

            $subscription->update(['status' => SubscriptionStatus::Canceling, 'ends_at' => $endsAt, 'past_due_at' => null]);

            $this->record($user, 'cancellation', $subscription->plan, 0, 'simulated', 'Simulated cancellation at the end of the period.');
        });

        return new BillingResult('Simulation: your plan is canceled and stays usable until the end of the period.');
    }

    public function resume(User $user): BillingResult
    {
        DB::transaction(function () use ($user) {
            $this->lock($user);

            $subscription = DemoBillingSubscription::query()->where('user_id', $user->id)->first();

            if ($subscription?->status !== SubscriptionStatus::Canceling || $subscription->ends_at->isPast()) {
                throw new BillingException('This subscription can\'t be resumed.');
            }

            $subscription->update(['status' => SubscriptionStatus::Active, 'ends_at' => null]);

            $this->record($user, 'resumed', $subscription->plan, 0, 'simulated', 'Simulated resumption of the subscription.');
        });

        return new BillingResult('Simulation: your subscription will continue.');
    }

    public function records(User $user): array
    {
        return DemoBillingTransaction::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit(50)
            ->get()
            ->map($this->toRecord(...))
            ->all();
    }

    public function findRecord(User $user, string $id): ?BillingRecord
    {
        $transaction = DemoBillingTransaction::query()->where('user_id', $user->id)->where('reference', $id)->first();

        return $transaction === null ? null : $this->toRecord($transaction);
    }

    public function portalUrl(User $user, string $returnUrl): ?string
    {
        return null;
    }

    /**
     * @throws BillingException
     */
    public function simulatePaymentFailure(User $user): void
    {
        DB::transaction(function () use ($user) {
            $this->lock($user);

            $subscription = $this->cancellable($user);

            $subscription->update(['status' => SubscriptionStatus::PastDue, 'past_due_at' => now()]);

            $this->record($user, 'payment_failed', $subscription->plan, 0, 'failed', 'Simulated failed payment. Nothing was charged.');
        });
    }

    /**
     * @throws BillingException
     */
    public function simulatePaymentRecovery(User $user): void
    {
        DB::transaction(function () use ($user) {
            $this->lock($user);

            $subscription = DemoBillingSubscription::query()->where('user_id', $user->id)->first();

            if ($subscription?->status !== SubscriptionStatus::PastDue) {
                throw new BillingException('There is no failed payment to recover.');
            }

            $subscription->update(['status' => SubscriptionStatus::Active, 'past_due_at' => null]);

            $this->record($user, 'payment_recovered', $subscription->plan, 0, 'simulated', 'Simulated successful retry. No payment was taken.');
        });
    }

    /**
     * @throws BillingException
     */
    public function simulateExpiration(User $user): void
    {
        DB::transaction(function () use ($user) {
            $this->lock($user);

            $subscription = DemoBillingSubscription::query()->where('user_id', $user->id)->first();

            if ($subscription === null || in_array($subscription->status, [SubscriptionStatus::Free, SubscriptionStatus::Expired], true)) {
                throw new BillingException('There is no subscription to expire.');
            }

            $subscription->update(['status' => SubscriptionStatus::Expired, 'ends_at' => now(), 'past_due_at' => null]);

            $this->record($user, 'expired', $subscription->plan, 0, 'simulated', 'Simulated expiration of the subscription.');
        });
    }

    public function restoreFree(User $user): void
    {
        DB::transaction(function () use ($user) {
            $this->lock($user);

            $updated = DemoBillingSubscription::query()->where('user_id', $user->id)->update([
                'plan' => 'free',
                'status' => SubscriptionStatus::Free->value,
                'current_period_start' => null,
                'current_period_end' => null,
                'past_due_at' => null,
                'ends_at' => null,
            ]);

            if ($updated > 0) {
                $this->record($user, 'restored_free', 'free', 0, 'simulated', 'Simulated return to the Free plan.');
            }
        });
    }

    /**
     * Only ever removes this user's two kinds of demo record.
     */
    public function reset(User $user): void
    {
        DB::transaction(function () use ($user) {
            $this->lock($user);

            DemoBillingSubscription::query()->where('user_id', $user->id)->delete();
            DemoBillingTransaction::query()->where('user_id', $user->id)->delete();
        });
    }

    private function canceling(DemoBillingSubscription $subscription, SubscriptionPlan $plan, SubscriptionPlan $default): SubscriptionState
    {
        return $subscription->ends_at->isFuture()
            ? new SubscriptionState($plan, $plan, SubscriptionStatus::Canceling, endsAt: $subscription->ends_at, reference: $subscription->reference)
            : new SubscriptionState($default, $plan, SubscriptionStatus::Expired, endsAt: $subscription->ends_at, reference: $subscription->reference);
    }

    private function pastDue(DemoBillingSubscription $subscription, SubscriptionPlan $plan, SubscriptionPlan $default): SubscriptionState
    {
        $graceEndsAt = $subscription->past_due_at->copy()->addDays((int) config('billing.past_due_grace_days'));

        return new SubscriptionState($graceEndsAt->isFuture() ? $plan : $default, $plan, SubscriptionStatus::PastDue, graceEndsAt: $graceEndsAt, reference: $subscription->reference);
    }

    /**
     * Worked out when read, so a demo subscription renews without a scheduled job.
     */
    private function nextRenewal(DemoBillingSubscription $subscription): Carbon
    {
        $renewal = $subscription->current_period_end->copy();

        while ($renewal->isPast()) {
            $renewal->addMonthNoOverflow();
        }

        return $renewal;
    }

    private function cancellable(User $user): DemoBillingSubscription
    {
        $subscription = DemoBillingSubscription::query()->where('user_id', $user->id)->first();

        if ($subscription === null || ! in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::Trialing, SubscriptionStatus::PastDue], true)) {
            throw new BillingException('There is no active subscription for this action.');
        }

        return $subscription;
    }

    private function existingReference(User $user): ?string
    {
        return DemoBillingSubscription::query()->where('user_id', $user->id)->value('reference');
    }

    /**
     * Serializes billing changes for one user, so two requests can't interleave.
     */
    private function lock(User $user): void
    {
        User::query()->whereKey($user->id)->lockForUpdate()->first();
    }

    private function record(User $user, string $type, string $plan, int $amountCents, string $status, string $description): void
    {
        DemoBillingTransaction::query()->create([
            'user_id' => $user->id,
            'reference' => self::TRANSACTION_PREFIX.Str::upper(Str::random(12)),
            'type' => $type,
            'plan' => $plan,
            'amount_cents' => $amountCents,
            'currency' => (string) config('billing.currency'),
            'status' => $status,
            'description' => $description,
        ]);
    }

    private function toRecord(DemoBillingTransaction $transaction): BillingRecord
    {
        return new BillingRecord(
            id: $transaction->reference,
            reference: $transaction->reference,
            date: $transaction->created_at,
            description: $transaction->description,
            amountCents: $transaction->amount_cents,
            currency: $transaction->currency,
            status: $transaction->status,
            simulated: true,
        );
    }
}
