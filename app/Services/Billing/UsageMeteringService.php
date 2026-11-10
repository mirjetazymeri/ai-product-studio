<?php

namespace App\Services\Billing;

use App\Enums\AiError;
use App\Enums\AiGenerationStatus;
use App\Enums\BillingMode;
use App\Enums\UsageAction;
use App\Models\AiGeneration;
use App\Models\UsageCounter;
use App\Models\UsageEvent;
use App\Models\User;
use App\Services\AI\Exceptions\AiGenerationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tracks the monthly AI allowance. Work reserves one unit before Gemini is called, then
 * either consumes it on success or releases it on failure.
 *
 * Periods are UTC calendar months for every plan, so a period never depends on a
 * payment date. History is never deleted: a new month simply uses a new counter.
 */
class UsageMeteringService
{
    private const DEADLOCK_ATTEMPTS = 3;

    public function __construct(
        private readonly PlanEntitlementService $entitlements,
        private readonly BillingManager $billing,
    ) {}

    public function currentPeriod(): string
    {
        return now('UTC')->format('Y-m');
    }

    public function periodEnd(): Carbon
    {
        return now('UTC')->startOfMonth()->addMonth();
    }

    public function used(User $user): int
    {
        return (int) UsageCounter::query()->where('user_id', $user->id)->where('period', $this->currentPeriod())->value('used');
    }

    public function remaining(User $user): int
    {
        return max(0, $this->entitlements->limit($user, 'ai_generations') - $this->used($user));
    }

    /**
     * Safe to repeat with the same key. The conditional update is a single statement,
     * so concurrent requests can't both take the last unit.
     *
     * @throws AiGenerationException when the allowance is used up
     */
    public function reserve(User $user, UsageAction $action, string $key, ?string $subjectType = null, ?int $subjectId = null, int $quantity = 1): UsageEvent
    {
        return DB::transaction(function () use ($user, $action, $key, $subjectType, $subjectId, $quantity) {
            $this->lockUser($user->id);

            $existing = UsageEvent::query()->where('user_id', $user->id)->where('idempotency_key', $key)->first();

            if ($existing !== null && $existing->status !== UsageEvent::RELEASED) {
                return $existing;
            }

            $plan = $this->entitlements->plan($user);
            $period = $existing->period ?? $this->currentPeriod();

            UsageCounter::query()->insertOrIgnore(['user_id' => $user->id, 'period' => $period, 'used' => 0, 'created_at' => now(), 'updated_at' => now()]);

            $this->takeFromCounter($user->id, $period, $quantity, $plan->limit('ai_generations'));

            $attributes = [
                'action' => $action,
                'quantity' => $quantity,
                'plan' => $plan->key,
                'period' => $period,
                'status' => UsageEvent::RESERVED,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'simulated' => $this->billing->provider()->mode() === BillingMode::Demo,
                'settled_at' => null,
            ];

            if ($existing !== null) {
                $existing->update($attributes);

                return $existing;
            }

            return UsageEvent::query()->create(['user_id' => $user->id, 'idempotency_key' => $key, ...$attributes]);
        }, self::DEADLOCK_ATTEMPTS);
    }

    /**
     * Taken first by everything that changes a user's usage, always in the same order.
     * Without it, concurrent requests deadlock in MySQL on the counter and event rows.
     */
    public function lockUser(int $userId): void
    {
        User::query()->whereKey($userId)->lockForUpdate()->first();
    }

    public function consume(int $userId, string $key): void
    {
        UsageEvent::query()
            ->where('user_id', $userId)
            ->where('idempotency_key', $key)
            ->where('status', UsageEvent::RESERVED)
            ->update(['status' => UsageEvent::CONSUMED, 'settled_at' => now()]);
    }

    /**
     * The conditional update makes this safe to repeat: only the first call gives back.
     */
    public function release(int $userId, string $key): void
    {
        DB::transaction(function () use ($userId, $key) {
            $this->lockUser($userId);

            $event = UsageEvent::query()->where('user_id', $userId)->where('idempotency_key', $key)->first();

            if ($event !== null && $event->status === UsageEvent::RESERVED) {
                $this->giveBack($event);
            }
        }, self::DEADLOCK_ATTEMPTS);
    }

    /**
     * Closes reservations left behind when a generation failed outside the normal path.
     */
    public function releaseForFailedGenerations(User $user): void
    {
        $failed = AiGeneration::query()
            ->where('user_id', $user->id)
            ->where('status', AiGenerationStatus::Failed->value)
            ->whereIn('id', UsageEvent::query()->where('user_id', $user->id)->where('status', UsageEvent::RESERVED)->where('subject_type', 'generation')->select('subject_id'))
            ->pluck('id');

        foreach ($failed as $id) {
            $this->release($user->id, self::generationKey($id));
        }
    }

    public static function generationKey(int $generationId): string
    {
        return 'generation:'.$generationId;
    }

    /**
     * Demo Billing only, so a limit can be tested without calling Gemini.
     */
    public function addSimulatedUsage(User $user, int $quantity): void
    {
        if ($quantity > 0) {
            $this->reserve($user, UsageAction::DemoSimulated, 'demo-usage:'.Str::uuid(), quantity: $quantity);
        }

        UsageEvent::query()
            ->where('user_id', $user->id)
            ->where('action', UsageAction::DemoSimulated->value)
            ->where('status', UsageEvent::RESERVED)
            ->update(['status' => UsageEvent::CONSUMED, 'settled_at' => now()]);
    }

    /**
     * Only undoes the demo control. Real AI usage is unaffected, and rows are kept.
     */
    public function clearSimulatedUsage(User $user): void
    {
        DB::transaction(function () use ($user) {
            $this->lockUser($user->id);

            $events = UsageEvent::query()
                ->where('user_id', $user->id)
                ->where('action', UsageAction::DemoSimulated->value)
                ->where('status', '!=', UsageEvent::RELEASED)
                ->get();

            $events->each($this->giveBack(...));
        });
    }

    private function takeFromCounter(int $userId, string $period, int $quantity, int $allowance): void
    {
        $updated = UsageCounter::query()
            ->where('user_id', $userId)
            ->where('period', $period)
            ->where('used', '<=', $allowance - $quantity)
            ->increment('used', $quantity);

        if ($updated !== 1) {
            throw new AiGenerationException(AiError::MonthlyLimitReached);
        }
    }

    private function giveBack(UsageEvent $event): void
    {
        UsageCounter::query()
            ->where('user_id', $event->user_id)
            ->where('period', $event->period)
            ->where('used', '>=', $event->quantity)
            ->decrement('used', $event->quantity);

        $event->update(['status' => UsageEvent::RELEASED, 'settled_at' => now()]);
    }
}
