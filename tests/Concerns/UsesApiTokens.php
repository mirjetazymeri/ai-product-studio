<?php

namespace Tests\Concerns;

use App\Enums\ApiAbility;
use App\Models\User;
use Illuminate\Support\Carbon;

trait UsesApiTokens
{
    /**
     * @param  list<string>|null  $abilities  every real ability when null
     */
    protected function tokenFor(User $user, ?array $abilities = null, ?Carbon $expiresAt = null): string
    {
        return $user->createToken('Test client', $abilities ?? ApiAbility::values(), $expiresAt)->plainTextToken;
    }

    /**
     * Guards remember their user, so each simulated client has to start clean.
     */
    protected function api(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->flushHeaders()->withToken($token);
    }

    protected function guest(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->flushHeaders();
    }
}
