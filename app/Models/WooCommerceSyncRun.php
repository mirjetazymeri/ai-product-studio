<?php

namespace App\Models;

use App\Enums\ContentLanguage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['woocommerce_store_id', 'rollback_of_id', 'language', 'status', 'error_summary', 'confirmed_at', 'completed_at'])]
class WooCommerceSyncRun extends Model
{
    public const PREVIEWING = 'previewing';

    public const READY = 'ready';

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const PARTIALLY_COMPLETED = 'partially_completed';

    public const FAILED = 'failed';

    public const CONFLICT = 'conflict';

    public const SKIPPED = 'skipped';

    public const ACTIVE = [self::PREVIEWING, self::QUEUED, self::RUNNING];

    protected $table = 'woocommerce_sync_runs';

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'language' => ContentLanguage::class,
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<WooCommerceStore, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(WooCommerceStore::class, 'woocommerce_store_id');
    }

    /**
     * @return HasMany<WooCommerceSyncItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(WooCommerceSyncItem::class, 'woocommerce_sync_run_id');
    }

    public function isRollback(): bool
    {
        return $this->rollback_of_id !== null;
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::COMPLETED, self::PARTIALLY_COMPLETED, self::FAILED, self::CONFLICT, self::SKIPPED], true);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::PREVIEWING => 'Building preview',
            self::READY => 'Waiting for confirmation',
            self::QUEUED => 'Pending',
            self::RUNNING => 'Running',
            self::COMPLETED => 'Completed',
            self::PARTIALLY_COMPLETED => 'Partially completed',
            self::FAILED => 'Failed',
            self::CONFLICT => 'Conflict',
            self::SKIPPED => 'Skipped',
            default => ucfirst($this->status),
        };
    }
}
