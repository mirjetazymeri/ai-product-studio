<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'operation', 'key_hash', 'request_hash', 'status', 'response_status', 'response', 'locked_at', 'expires_at'])]
#[Hidden(['response'])]
class ApiIdempotencyKey extends Model
{
    public const IN_PROGRESS = 'in_progress';

    public const COMPLETED = 'completed';

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'response' => 'encrypted:array',
            'locked_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
