<?php

namespace App\Services\Billing;

use App\Models\BillingWebhookEvent;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Subscription;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Stripe may repeat events or deliver them out of order. Each event ID is processed once.
 *
 * Stripe's timestamps have one-second precision, so only a strictly older event counts
 * as stale. A deleted subscription is final. billing:reconcile corrects anything left over.
 */
class StripeWebhookLedger
{
    private const IN_PROGRESS_SECONDS = 300;

    /**
     * @param  array<string, mixed>  $payload  already verified by its signature
     * @param  Closure(): Response  $handler
     */
    public function process(array $payload, Closure $handler): Response
    {
        $objectId = is_string($payload['data']['object']['id'] ?? null) ? $payload['data']['object']['id'] : null;
        $created = (int) ($payload['created'] ?? 0);

        $event = $this->claim($payload, $objectId, $created);

        if ($event === null) {
            return response('Already processed.', 200);
        }

        if ($this->isStale($event, $payload['type'], $objectId, $created)) {
            $event->update(['status' => BillingWebhookEvent::IGNORED, 'processed_at' => now()]);

            return response('Ignored: a newer event was already applied.', 200);
        }

        try {
            $response = $handler();
            $this->reconcilePastDue($payload, $objectId);
        } catch (Throwable $exception) {
            // Releasing the ID lets Stripe's retry run it again.
            $event->delete();

            Log::error('A Stripe webhook could not be processed.', ['event_id' => $event->stripe_event_id, 'type' => $event->type, 'error' => $exception::class]);

            throw $exception;
        }

        $event->update(['status' => BillingWebhookEvent::PROCESSED, 'processed_at' => now()]);

        return $response;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return BillingWebhookEvent|null null when this event was already handled
     */
    private function claim(array $payload, ?string $objectId, int $created): ?BillingWebhookEvent
    {
        $attributes = ['stripe_event_id' => $payload['id'], 'type' => mb_substr((string) $payload['type'], 0, 100), 'object_id' => $objectId, 'event_created_at' => $created, 'status' => BillingWebhookEvent::PROCESSING];

        try {
            return BillingWebhookEvent::query()->create($attributes);
        } catch (UniqueConstraintViolationException) {
            $existing = BillingWebhookEvent::query()->where('stripe_event_id', $payload['id'])->firstOrFail();

            if ($existing->status !== BillingWebhookEvent::PROCESSING) {
                return null;
            }

            if ($existing->updated_at->gt(now()->subSeconds(self::IN_PROGRESS_SECONDS))) {
                abort(409, 'This event is still being processed.');
            }

            $existing->touch();

            return $existing;
        }
    }

    private function isStale(BillingWebhookEvent $event, string $type, ?string $objectId, int $created): bool
    {
        if ($objectId === null || ! str_starts_with($type, 'customer.subscription.')) {
            return false;
        }

        $processed = BillingWebhookEvent::query()
            ->where('object_id', $objectId)
            ->where('status', BillingWebhookEvent::PROCESSED)
            ->whereKeyNot($event->id);

        if ((clone $processed)->where('event_created_at', '>', $created)->exists()) {
            return true;
        }

        return $type !== 'customer.subscription.deleted'
            && (clone $processed)->where('type', 'customer.subscription.deleted')->exists();
    }

    /**
     * The grace period is measured from the first failure, which Cashier doesn't record.
     *
     * @param  array<string, mixed>  $payload
     */
    private function reconcilePastDue(array $payload, ?string $objectId): void
    {
        if ($objectId === null || ! str_starts_with($payload['type'], 'customer.subscription.')) {
            return;
        }

        $subscription = Subscription::query()->where('stripe_id', $objectId)->first();

        if ($subscription === null) {
            return;
        }

        if ($subscription->stripe_status !== 'past_due') {
            $subscription->forceFill(['past_due_at' => null])->save();
        } elseif ($subscription->past_due_at === null) {
            $subscription->forceFill(['past_due_at' => now()])->save();
        }
    }
}
