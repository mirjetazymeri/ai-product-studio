<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreApiTokenRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class ApiTokenController extends Controller
{
    public const FLASH_KEY = 'new_api_token';

    /**
     * Sanctum stores only a hash. The plain value is flashed encrypted, because session
     * rows aren't, and it can be shown exactly once.
     */
    public function store(StoreApiTokenRequest $request): RedirectResponse
    {
        $token = $request->user()->createToken(
            $request->validated('name'),
            $request->validated('abilities'),
            $request->expiresAt(),
        );

        return to_route('settings.edit')
            ->withFragment('api-access')
            ->with(self::FLASH_KEY, Crypt::encryptString($token->plainTextToken))
            ->with('status', 'API token created. Copy it now, because it won\'t be shown again.');
    }

    /**
     * Looks only through the user's own tokens, so another user's ID matches nothing.
     */
    public function destroy(Request $request, string $token): RedirectResponse
    {
        $request->user()->tokens()->whereKey($token)->firstOrFail()->delete();

        return to_route('settings.edit')->withFragment('api-access')->with('status', 'API token revoked.');
    }
}
