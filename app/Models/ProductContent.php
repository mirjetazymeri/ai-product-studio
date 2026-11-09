<?php

namespace App\Models;

use App\Enums\ContentLanguage;
use App\Enums\ContentSource;
use Database\Factories\ProductContentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['language', 'title', 'short_description', 'long_description', 'meta_title', 'meta_description', 'slug', 'keywords', 'source'])]
class ProductContent extends Model
{
    /** @use HasFactory<ProductContentFactory> */
    use HasFactory;

    protected $attributes = [
        'source' => 'manual',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'language' => ContentLanguage::class,
            'source' => ContentSource::class,
            'keywords' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
