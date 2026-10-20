<?php

namespace App\Http\Controllers\Settings;

use App\Data\FlashData;
// @feature passkeys
use App\Data\PasskeyData;
// @end-feature
use App\Data\PasswordUpdateData;
use App\Enums\ToastType;
use App\Http\Controllers\Controller;
// @feature 2fa
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
// @end-feature
use App\Models\User;
use App\Pages\Settings\SecurityPage;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
// @feature 2fa
use Illuminate\Http\Request;
// @end-feature
use Illuminate\Validation\Rules\Password;
// @feature 2fa|passkeys
use Laravel\Fortify\Features;
// @end-feature
// @feature 2fa
use Spatie\LaravelData\Optional;
// @end-feature

class SecurityController extends Controller
{
    /**
     * Show the user's security settings page.
     */
    // @feature 2fa
    public function edit(Request $request, #[CurrentUser] User $user): SecurityPage
    // @end-feature
    // @feature !2fa
    // @feature passkeys
    public function edit(#[CurrentUser] User $user): SecurityPage
    // @end-feature
    // @feature !passkeys
    public function edit(): SecurityPage
    // @end-feature
    // @end-feature
    {
        // @feature 2fa
        $canManageTwoFactor = Features::canManageTwoFactorAuthentication();

        if ($canManageTwoFactor) {
            // Problem P-112: an action takes no FormRequest. This one validates nothing and only lends Fortify's
            // two-factor state handling (user and session) a request, so it's made from the request instead.
            TwoFactorAuthenticationRequest::createFrom($request)->ensureStateIsValid();
        }

        // @end-feature
        return new SecurityPage(
            passwordRules: Password::defaults()->toPasswordRulesString(),
            // @feature 2fa
            canManageTwoFactor: $canManageTwoFactor,
            twoFactorEnabled: $canManageTwoFactor ? $user->hasEnabledTwoFactorAuthentication() : Optional::create(),
            requiresConfirmation: $canManageTwoFactor
                ? Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm')
                : Optional::create(),
            // @end-feature
            // @feature passkeys
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
            // @end-feature
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
