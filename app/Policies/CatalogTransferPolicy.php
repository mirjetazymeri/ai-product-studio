<?php

namespace App\Policies;

use App\Models\CatalogTransfer;
use App\Models\User;

class CatalogTransferPolicy
{
    public function view(User $user, CatalogTransfer $transfer): bool
    {
        return $user->id === $transfer->user_id;
    }
}
