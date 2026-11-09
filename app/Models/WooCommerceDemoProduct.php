<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The simulated remote copy of a product in a demo store, in WooCommerce's own shape.
 */
#[Fillable(['user_id', 'remote_id', 'payload'])]
class WooCommerceDemoProduct extends Model
{
    protected $table = 'woocommerce_demo_products';

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    /**
     * @return BelongsTo<WooCommerceStore, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(WooCommerceStore::class, 'woocommerce_store_id');
    }
}
