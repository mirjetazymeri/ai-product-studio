<?php

namespace App\Http\Controllers\Settings;

use App\Enums\ApiAbility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateProfileRequest;
use App\Services\Billing\PlanEntitlementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request, PlanEntitlementService $entitlements): View
    {
        $newToken = $request->session()->get(ApiTokenController::FLASH_KEY);

        return view('settings.edit', [
            'user' => $request->user(),
            'apiTokens' => $request->user()->tokens()->latest('id')->get(),
            'apiAbilities' => ApiAbility::cases(),
            'apiIncluded' => $entitlements->can($request->user(), 'api.access'),
            'newApiToken' => $newToken === null ? null : Crypt::decryptString($newToken),
        ]);
    }

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        $user = $request->user();
        $user->fill($request->validated());

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        return to_route('settings.edit')->with('status', 'Profile updated.');
    }
}
