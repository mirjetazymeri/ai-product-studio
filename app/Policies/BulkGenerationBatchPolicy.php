<?php

namespace App\Policies;

use App\Models\BulkGenerationBatch;
use App\Models\User;

class BulkGenerationBatchPolicy
{
    public function retry(User $user, BulkGenerationBatch $batch): bool
    {
        return $user->id === $batch->user_id;
    }
}
