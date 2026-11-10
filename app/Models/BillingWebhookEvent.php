<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Identifiers only. A webhook's contents are never stored or logged.
 */
#[Fillable(['stripe_event_id', 'type', 'object_id', 'event_created_at', 'status', 'processed_at'])]
class BillingWebhookEvent extends Model
{
    public const PROCESSING = 'processing';

    public const PROCESSED = 'processed';

    public const IGNORED = 'ignored';

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }
}
