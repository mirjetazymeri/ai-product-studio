<?php

namespace App\Models;

use App\Enums\ContentLanguage;
use App\Enums\ProductStatus;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// user_id is deliberately not fillable: ownership is only set through $user->products().
#[Fillable(['original_name', 'sku', 'category', 'material', 'details', 'status'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'draft',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
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
     * @return HasMany<ProductContent, $this>
     */
    public function contents(): HasMany
    {
        return $this->hasMany(ProductContent::class);
    }

    /**
     * @return HasMany<ProductExternalReference, $this>
     */
    public function externalReferences(): HasMany
    {
        return $this->hasMany(ProductExternalReference::class);
    }

    /**
     * Whether this product only exists because of a demo store import.
     */
    public function isDemo(): bool
    {
        return $this->demo_store_id !== null;
    }

    public function contentFor(ContentLanguage $language): ?ProductContent
    {
        return $this->contents->first(fn (ProductContent $content) => $content->language === $language);
    }

    /**
     * The OR is grouped so it can never widen a user-scoped query.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        if (blank($term)) {
            return;
        }

        $query->where(function (Builder $query) use ($term) {
            $query->where('original_name', 'like', "%{$term}%")
                ->orWhere('sku', 'like', "%{$term}%");
        });
    }

    /**
     * @param  Builder<Product>  $query
     */
    public function scopeWithStatus(Builder $query, ?ProductStatus $status): void
    {
        if ($status !== null) {
            $query->where('status', $status->value);
        }
    }

    /**
     * @param  Builder<Product>  $query
     */
    public function scopeSortedByCreation(Builder $query, string $direction = 'desc'): void
    {
        $query->orderBy('created_at', $direction)->orderBy('id', $direction);
    }
}
