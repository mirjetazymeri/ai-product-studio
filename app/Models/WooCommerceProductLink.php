<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Connects one local product to one product in one store.
 */
#[Fillable(['user_id', 'product_id', 'remote_id', 'remote_type', 'sync_mode', 'remote_sku', 'remote_status', 'remote_modified_gmt', 'remote_language', 'permalink', 'translations', 'metadata', 'last_imported_at', 'last_synced_at'])]
class WooCommerceProductLink extends Model
{
    public const MODE_FULL = 'full';

    public const MODE_DESCRIPTIVE = 'descriptive';

    public const MODE_READ_ONLY = 'read_only';

    protected $table = 'woocommerce_product_links';

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'translations' => 'array',
            'metadata' => 'array',
            'last_imported_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WooCommerceStore, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(WooCommerceStore::class, 'woocommerce_store_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isReadOnly(): bool
    {
        return $this->sync_mode === self::MODE_READ_ONLY;
    }

    public function syncModeLabel(): string
    {
        return match ($this->sync_mode) {
            self::MODE_FULL => 'Full content',
            self::MODE_DESCRIPTIVE => 'Descriptive fields',
            default => 'Read-only',
        };
    }
}
