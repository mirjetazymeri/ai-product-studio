<?php

namespace App\Models;

use App\Enums\ContentLanguage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product in one sync run. "fields" holds the field-level comparison shown to the
 * user, and "previous_values" holds what the store had before it was changed.
 */
#[Fillable(['woocommerce_product_link_id', 'product_id', 'rollback_of_item_id', 'remote_id', 'product_name', 'language', 'status', 'attempts', 'fields', 'previous_values', 'applied_values', 'conflicts', 'error_code', 'message'])]
class WooCommerceSyncItem extends Model
{
    public const PENDING = 'pending';

    public const READY = 'ready';

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const PARTIALLY_COMPLETED = 'partially_completed';

    public const FAILED = 'failed';

    public const CONFLICT = 'conflict';

    public const SKIPPED = 'skipped';

    protected $table = 'woocommerce_sync_items';

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'language' => ContentLanguage::class,
            'fields' => 'array',
            'previous_values' => 'array',
            'applied_values' => 'array',
            'conflicts' => 'array',
        ];
    }

    /**
     * @return BelongsTo<WooCommerceSyncRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(WooCommerceSyncRun::class, 'woocommerce_sync_run_id');
    }

    /**
     * @return BelongsTo<WooCommerceProductLink, $this>
     */
    public function link(): BelongsTo
    {
        return $this->belongsTo(WooCommerceProductLink::class, 'woocommerce_product_link_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::PARTIALLY_COMPLETED => 'Partially completed',
            self::READY => 'Ready',
            default => ucfirst($this->status),
        };
    }
}
