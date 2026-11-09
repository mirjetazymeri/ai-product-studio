<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WooCommerceStore;

class WooCommerceStorePolicy
{
    public function manage(User $user, WooCommerceStore $store): bool
    {
        return $user->id === $store->user_id;
    }
}
