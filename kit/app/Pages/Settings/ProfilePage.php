<?php

namespace App\Pages\Settings;

use StrictlyLaravel\Inertia\Page;

// @feature email-verification
final class ProfilePage extends Page
{
    public function __construct(
        public bool $mustVerifyEmail,
        public ?string $status,
    ) {}
}
// @end-feature
// @feature !email-verification
final class ProfilePage extends Page {}
// @end-feature
