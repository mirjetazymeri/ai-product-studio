<?php

namespace App\Policies;

use App\Models\BulkGenerationItem;
use App\Models\User;

class BulkGenerationItemPolicy
{
    public function review(User $user, BulkGenerationItem $item): bool
    {
        return $user->id === $item->user_id;
    }
}
