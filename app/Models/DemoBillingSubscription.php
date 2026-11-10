<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A simulated subscription. Only DemoBillingProvider reads this table.
 */
#[Fillable(['user_id', 'reference', 'plan', 'status', 'current_period_start', 'current_period_end', 'past_due_at', 'ends_at'])]
class DemoBillingSubscription extends Model
{
    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'past_due_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
