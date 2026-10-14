<?php

namespace App\Http\Controllers\Settings;

use App\Data\PasskeyData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use App\Models\User;
use App\Pages\Settings\SecurityPage;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Spatie\LaravelData\Optional;

class SecurityController extends Controller
{
    /**
     * Show the user's security settings page.
     */
    public function edit(TwoFactorAuthenticationRequest $request, #[CurrentUser] User $user): SecurityPage
    {
        $canManageTwoFactor = Features::canManageTwoFactorAuthentication();

        if ($canManageTwoFactor) {
            $request->ensureStateIsValid();
        }

        return new SecurityPage(
            passwordRules: Password::defaults()->toPasswordRulesString(),
            canManageTwoFactor: $canManageTwoFactor,
            twoFactorEnabled: $canManageTwoFactor ? $user->hasEnabledTwoFactorAuthentication() : Optional::create(),
            requiresConfirmation: $canManageTwoFactor
                ? Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm')
                : Optional::create(),
            canManagePasskeys: Features::canManagePasskeys(),
            passkeys: Features::canManagePasskeys()
                ? $user
                    ->passkeys()
                    ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
                    ->latest()
                    ->get()
                    ->map(PasskeyData::fromPasskey(...))
                    ->values()
                    ->all()
                : [],
        );
    }

    /**
     * Update the user's password.
     */
    public function update(PasswordUpdateRequest $request, #[CurrentUser] User $user): RedirectResponse
    {
        $user->update([
            'password' => $request->string('password')->toString(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Password updated.')]);

        return back();
    }
}
