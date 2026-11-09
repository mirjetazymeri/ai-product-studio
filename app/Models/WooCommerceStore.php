<?php

namespace App\Models;

use App\Enums\ContentLanguage;
use App\Enums\WooCommerceError;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// user_id is deliberately not fillable: ownership is only set through $user->wooCommerceStores().
#[Fillable(['name', 'url', 'connection_type', 'consumer_key', 'consumer_secret', 'status', 'last_error_code', 'content_language', 'capabilities', 'simulated_outage', 'last_checked_at', 'last_import_at', 'last_sync_at', 'disconnected_at'])]
#[Hidden(['consumer_key', 'consumer_secret'])]
class WooCommerceStore extends Model
{
    public const UNTESTED = 'untested';

    public const CONNECTED = 'connected';

    public const FAILING = 'failing';

    public const DISCONNECTED = 'disconnected';

    public const TYPE_LIVE = 'live';

    public const TYPE_DEMO = 'demo';

    /** Reserved by RFC 2606 and refused for real stores, so it can only be the demo. */
    public const DEMO_URL = 'https://demo-store.invalid';

    protected $table = 'woocommerce_stores';

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'consumer_key' => 'encrypted',
            'consumer_secret' => 'encrypted',
            'content_language' => ContentLanguage::class,
            'capabilities' => 'array',
            'simulated_outage' => 'boolean',
            'last_checked_at' => 'datetime',
            'last_import_at' => 'datetime',
            'last_sync_at' => 'datetime',
            'disconnected_at' => 'datetime',
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
     * @return HasMany<WooCommerceProductLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(WooCommerceProductLink::class, 'woocommerce_store_id');
    }

    /**
     * @return HasMany<WooCommerceSyncRun, $this>
     */
    public function syncRuns(): HasMany
    {
        return $this->hasMany(WooCommerceSyncRun::class, 'woocommerce_store_id');
    }

    /**
     * @return HasMany<WooCommerceDemoProduct, $this>
     */
    public function demoProducts(): HasMany
    {
        return $this->hasMany(WooCommerceDemoProduct::class, 'woocommerce_store_id');
    }

    public function isDemo(): bool
    {
        return $this->connection_type === self::TYPE_DEMO;
    }

    /**
     * Demo stores never hold credentials, so missing ones don't mean anything for them.
     */
    public function isDisconnected(): bool
    {
        return $this->status === self::DISCONNECTED
            || (! $this->isDemo() && (blank($this->consumer_key) || blank($this->consumer_secret)));
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::CONNECTED => 'Connected',
            self::FAILING => 'Connection problem',
            self::DISCONNECTED => 'Disconnected',
            default => 'Not tested',
        };
    }

    public function lastErrorMessage(): ?string
    {
        return WooCommerceError::tryFrom((string) $this->last_error_code)?->message();
    }

    public function externalReferenceSource(): string
    {
        return 'woocommerce-'.$this->id;
    }
}
