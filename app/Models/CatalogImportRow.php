<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One product waiting to be imported (which may span several source rows, one per
 * language), or one rejected source row.
 */
#[Fillable(['catalog_transfer_id', 'external_key', 'first_row', 'row_count', 'status', 'payload', 'errors', 'match_type', 'result', 'result_message'])]
class CatalogImportRow extends Model
{
    public const VALID = 'valid';

    public const INVALID = 'invalid';

    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    public $timestamps = false;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'errors' => 'array',
        ];
    }
}
