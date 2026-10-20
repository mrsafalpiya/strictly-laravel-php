<?php

namespace App\Pages\Settings;

// @feature passkeys
use App\Data\PasskeyData;
// @end-feature
// @feature 2fa
use Spatie\LaravelData\Optional;
// @end-feature
use StrictlyLaravel\Inertia\Page;

final class SecurityPage extends Page
{
    public function __construct(
        public string $passwordRules,
        // @feature 2fa
        public bool $canManageTwoFactor,
        /** Sent only when the app can manage two-factor authentication, like the next one. */
        public bool|Optional $twoFactorEnabled,
        public bool|Optional $requiresConfirmation,
        // @end-feature
        // @feature passkeys
        public bool $canManagePasskeys,
        /** @var list<PasskeyData> */
        public array $passkeys,
        // @end-feature
    ) {}
}
