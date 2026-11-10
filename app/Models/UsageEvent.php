<?php

namespace App\Models;

use App\Enums\UsageAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'action', 'quantity', 'plan', 'period', 'status', 'idempotency_key', 'subject_type', 'subject_id', 'simulated', 'settled_at'])]
class UsageEvent extends Model
{
    public const RESERVED = 'reserved';

    public const CONSUMED = 'consumed';

    public const RELEASED = 'released';

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'action' => UsageAction::class,
            'simulated' => 'boolean',
            'settled_at' => 'datetime',
        ];
    }
}
