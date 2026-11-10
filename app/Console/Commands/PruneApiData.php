<?php

namespace App\Console\Commands;

use App\Models\ApiIdempotencyKey;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;

#[Signature('api:prune')]
#[Description('Remove expired idempotency records and long-expired API tokens')]
class PruneApiData extends Command
{
    public function handle(): int
    {
        $keys = ApiIdempotencyKey::query()->where('expires_at', '<', now())->delete();

        $tokens = PersonalAccessToken::query()
            ->where('expires_at', '<', now()->subHours((int) config('api.expired_token_retention_hours')))
            ->delete();

        $this->info("Removed {$keys} idempotency records and {$tokens} expired tokens.");

        return self::SUCCESS;
    }
}
