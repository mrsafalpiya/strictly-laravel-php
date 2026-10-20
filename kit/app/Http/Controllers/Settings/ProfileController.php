<?php

namespace App\Http\Controllers\Settings;

use App\Data\FlashData;
use App\Data\ProfileDeleteData;
use App\Data\ProfileUpdateData;
use App\Enums\ToastType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Pages\Settings\ProfilePage;
use Illuminate\Container\Attributes\CurrentUser;
// @feature email-verification
use Illuminate\Contracts\Auth\MustVerifyEmail;
// @end-feature
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    // @feature email-verification
    public function edit(Request $request): ProfilePage
    {
        $status = $request->session()->get('status');

        return new ProfilePage(
            mustVerifyEmail: $request->user() instanceof MustVerifyEmail,
            status: is_string($status) ? $status : null,
        );
    }
    // @end-feature
    // @feature !email-verification
    public function edit(): ProfilePage
    {
        return new ProfilePage;
    }
    // @end-feature

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateData $data, #[CurrentUser] User $user): RedirectResponse
    {
        $user->fill(['name' => $data->name, 'email' => $data->email]);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        FlashData::toast(ToastType::Success, __('Profile updated.'));

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteData $data, Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
