<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
// @feature 2fa
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
// @end-feature
use Laravel\Fortify\Features;
// @feature 2fa
use PragmaRX\Google2FA\Google2FA;
// @end-feature

/*
 * The behaviour tests leave some shapes empty (no status message, no passkeys), and a page's Contract proves its type
 * only for the values a test produced. These tests produce the rest, so Contract Coverage (`php artisan
 * strictly:coverage --strict`, run by `composer ci:check`) finds no page or shape that tsc never checked.
 */

test('the auth and settings pages show a status message', function () {
    $this->withSession(['status' => 'We have emailed your password reset link.'])
        ->get(route('password.request'))
        ->assertInertia(fn (Assert $page) => $page->where('status', 'We have emailed your password reset link.'));

    $this->withSession(['status' => 'Your password has been reset.'])
        ->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page->where('status', 'Your password has been reset.'));
    // @feature email-verification

    $this->actingAs(User::factory()->unverified()->create())
        ->withSession(['status' => 'verification-link-sent'])
        ->get(route('verification.notice'))
        ->assertInertia(fn (Assert $page) => $page->where('status', 'verification-link-sent'));

    $this->actingAs(User::factory()->create())
        ->withSession(['status' => 'verification-link-sent'])
        ->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('status', 'verification-link-sent'));
    // @end-feature
});

// @feature !2fa
test('the security page is displayed', function () {
    $this->actingAs(User::factory()->create())
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/security'));
});

// @end-feature
// @feature passkeys
test('the security page lists passkeys', function () {
    $this->skipUnlessFortifyHas(Features::passkeys());

    $user = User::factory()->create();
    // One never used, from an unknown authenticator; one used, from a known one.
    $user->passkeys()->create([
        'name' => 'MacBook Pro',
        'credential_id' => 'credential-1',
        'credential' => ['aaguid' => '00000000-0000-0000-0000-000000000000'],
    ]);
    $user->passkeys()->create([
        'name' => 'Phone',
        'credential_id' => 'credential-2',
        'credential' => ['aaguid' => 'fbfc3007-154e-4ecc-8c0b-6e020557d7bd'],
    ])->forceFill(['last_used_at' => now()->subDay()])->save();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertInertia(fn (Assert $page) => $page->has('passkeys', 2));
});

// @end-feature
/*
 * Fortify's routes are typed by what config/strictly.php declares for them. Each one a test submits to or calls is
 * checked against its declaration: every key Fortify validates, reads or reports an error for must be a field of it,
 * and every response must match it. These tests reach the ones the behaviour tests don't.
 */

// @feature password-confirmation
test('the password can be confirmed', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('password.confirm.store'), ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();
});

// @end-feature
// @feature 2fa
test('the two-factor challenge takes a recovery code', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    $user = User::factory()->withTwoFactor()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));

    $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

// @end-feature
// @feature 2fa
test('the two-factor challenge takes a code from the authenticator app', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    $secret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();
    $user = User::factory()->withTwoFactor()->create(['two_factor_secret' => encrypt($secret)]);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));

    $this->post(route('two-factor.login.store'), ['code' => app(Google2FA::class)->getCurrentOtp($secret)])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

// @end-feature
// @feature registration
test('a new user can ask to be remembered when they register', function () {
    $this->skipUnlessFortifyHas(Features::registration());

    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'remembered@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'remember' => true,
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
    expect(auth()->user()?->getRememberToken())->not->toBeNull();
});

// @end-feature
// @feature 2fa
test('two-factor authentication can be enabled again with a new secret', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    $user = User::factory()->withTwoFactor()->create();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    $this->post(route('two-factor.enable'), ['force' => true])->assertRedirect();

    expect(decrypt((string) $user->fresh()?->two_factor_secret))->not->toBe('secret');
});

// @end-feature
// @feature 2fa
test('two-factor authentication is enabled and set up with its JSON endpoints', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    $user = User::factory()->create();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    $this->post(route('two-factor.enable'))->assertRedirect();

    $this->getJson(route('two-factor.qr-code'))->assertOk()->assertJsonStructure(['svg', 'url']);
    $this->getJson(route('two-factor.secret-key'))->assertOk()->assertJsonStructure(['secretKey']);
    $this->getJson(route('two-factor.recovery-codes'))->assertOk()->assertJsonCount(8);

    // A wrong code is refused with an error on the confirmation form's field.
    $this->post(route('two-factor.confirm'), ['code' => '000000'])
        ->assertSessionHasErrors('code', errorBag: 'confirmTwoFactorAuthentication');
});

// @end-feature
test('the password reset page reads the email from its link', function () {
    $this->skipUnlessFortifyHas(Features::resetPasswords());

    $this->get(route('password.reset', ['token' => 'token', 'email' => 'taylor@example.com']))
        ->assertInertia(fn (Assert $page) => $page->where('email', 'taylor@example.com'));
});

test('the appearance page is displayed', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('appearance.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/appearance'));
});
