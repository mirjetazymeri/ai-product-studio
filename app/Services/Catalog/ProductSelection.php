<?php

namespace App\Services\Catalog;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Turns "these checked products" or "everything matching this filter" into a query.
 * It always starts from the user's own products, so other users' IDs simply match nothing.
 */
final class ProductSelection
{
    /**
     * @param  array<string, mixed>  $input  product_ids, all_matching, search, status
     * @return HasMany<Product, User>
     */
    public function query(User $user, array $input): HasMany
    {
        $query = $user->products();

        if (empty($input['all_matching']) && ! empty($input['product_ids'])) {
            return $query->whereIn('id', array_map('intval', (array) $input['product_ids']));
        }

        return $query
            ->search(is_string($input['search'] ?? null) ? $input['search'] : null)
            ->withStatus(ProductStatus::tryFrom((string) ($input['status'] ?? '')));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function hasExplicitSelection(array $input): bool
    {
        return ! empty($input['all_matching']) || ! empty($input['product_ids']);
    }
}
