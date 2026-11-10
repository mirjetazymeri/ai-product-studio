<?php

namespace App\Policies;

use App\Models\AiGeneration;
use App\Models\User;

class AiGenerationPolicy
{
    public function view(User $user, AiGeneration $generation): bool
    {
        return $user->id === $generation->user_id;
    }

    public function review(User $user, AiGeneration $generation): bool
    {
        return $user->id === $generation->user_id;
    }

    public function save(User $user, AiGeneration $generation): bool
    {
        return $user->id === $generation->user_id;
    }
}
