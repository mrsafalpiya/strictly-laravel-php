<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;

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

    $this->actingAs(User::factory()->unverified()->create())
        ->withSession(['status' => 'verification-link-sent'])
        ->get(route('verification.notice'))
        ->assertInertia(fn (Assert $page) => $page->where('status', 'verification-link-sent'));

    $this->actingAs(User::factory()->create())
        ->withSession(['status' => 'verification-link-sent'])
        ->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('status', 'verification-link-sent'));
});

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

test('the appearance page is displayed', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('appearance.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/appearance'));
});
