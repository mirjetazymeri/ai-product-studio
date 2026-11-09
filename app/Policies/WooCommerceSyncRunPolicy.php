<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WooCommerceSyncRun;

class WooCommerceSyncRunPolicy
{
    public function manage(User $user, WooCommerceSyncRun $run): bool
    {
        return $user->id === $run->user_id;
    }
}
