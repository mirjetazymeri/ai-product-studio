<?php

namespace App\Models;

use App\Enums\CatalogTransferStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// user_id is deliberately not fillable: ownership is only set through $user->catalogTransfers().
#[Fillable(['operation', 'file_type', 'original_name', 'stored_path', 'status', 'total_records', 'successful_records', 'failed_records', 'options', 'summary', 'error_summary', 'completed_at', 'expires_at'])]
class CatalogTransfer extends Model
{
    public const IMPORT = 'import';

    public const EXPORT = 'export';

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'status' => CatalogTransferStatus::class,
            'options' => 'array',
            'summary' => 'array',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
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
     * @return HasMany<CatalogImportRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(CatalogImportRow::class);
    }

    public function isImport(): bool
    {
        return $this->operation === self::IMPORT;
    }
}
