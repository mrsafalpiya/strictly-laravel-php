<?php

namespace App\Pages\Settings;

use App\Data\PasskeyData;
use Spatie\LaravelData\Optional;
use StrictlyLaravel\Inertia\Page;

final class SecurityPage extends Page
{
    public function __construct(
        public string $passwordRules,
        public bool $canManageTwoFactor,
        /** Sent only when the app can manage two-factor authentication, like the next one. */
        public bool|Optional $twoFactorEnabled,
        public bool|Optional $requiresConfirmation,
        public bool $canManagePasskeys,
        /** @var array<int, PasskeyData> */
        public array $passkeys,
    ) {}
}
