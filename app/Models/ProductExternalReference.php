<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A product's ID in an outside system. "source" names that system, such as a file
 * label today or a store platform later, and is always read together with external_id.
 */
#[Fillable(['user_id', 'source', 'external_id'])]
class ProductExternalReference extends Model
{
    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
