<?php

namespace App\Http\Controllers\Settings;

use App\Data\FlashData;
use App\Data\PasskeyData;
use App\Data\PasswordUpdateData;
use App\Enums\ToastType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use App\Models\User;
use App\Pages\Settings\SecurityPage;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Features;
use Spatie\LaravelData\Optional;

class SecurityController extends Controller
{
    /**
     * Show the user's security settings page.
     */
    public function edit(Request $request, #[CurrentUser] User $user): SecurityPage
    {
        $canManageTwoFactor = Features::canManageTwoFactorAuthentication();

        if ($canManageTwoFactor) {
            // Problem P-112: an action takes no FormRequest. This one validates nothing and only lends Fortify's
            // two-factor state handling (user and session) a request, so it's made from the request instead.
            TwoFactorAuthenticationRequest::createFrom($request)->ensureStateIsValid();
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
    public function update(PasswordUpdateData $data, #[CurrentUser] User $user): RedirectResponse
    {
        $user->update([
            'password' => $data->password,
        ]);

        FlashData::toast(ToastType::Success, __('Password updated.'));

        return back();
    }
}
