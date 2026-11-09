<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The user's real catalog. Products from a demo store are reached through that store,
     * which keeps them out of lists, statistics, exports, file imports and SKU matching.
     *
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class)->whereNull('demo_store_id');
    }

    /**
     * Real requests only. Sample output is not a generation: it has no tokens or quota.
     *
     * @return HasMany<AiGeneration, $this>
     */
    public function aiGenerations(): HasMany
    {
        return $this->hasMany(AiGeneration::class)->real();
    }

    /**
     * @return HasMany<AiProductAnalysis, $this>
     */
    public function aiProductAnalyses(): HasMany
    {
        return $this->hasMany(AiProductAnalysis::class);
    }

    /**
     * @return HasMany<CatalogTransfer, $this>
     */
    public function catalogTransfers(): HasMany
    {
        return $this->hasMany(CatalogTransfer::class);
    }

    /**
     * @return HasMany<BulkGenerationBatch, $this>
     */
    public function bulkGenerationBatches(): HasMany
    {
        return $this->hasMany(BulkGenerationBatch::class);
    }

    /**
     * @return HasMany<BulkGenerationItem, $this>
     */
    public function bulkGenerationItems(): HasMany
    {
        return $this->hasMany(BulkGenerationItem::class);
    }

    /**
     * @return HasMany<WooCommerceStore, $this>
     */
    public function wooCommerceStores(): HasMany
    {
        return $this->hasMany(WooCommerceStore::class);
    }

    /**
     * @return HasMany<WooCommerceSyncRun, $this>
     */
    public function wooCommerceSyncRuns(): HasMany
    {
        return $this->hasMany(WooCommerceSyncRun::class);
    }

    /**
     * @return HasManyThrough<ProductContent, Product, $this>
     */
    public function productContents(): HasManyThrough
    {
        return $this->hasManyThrough(ProductContent::class, Product::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
