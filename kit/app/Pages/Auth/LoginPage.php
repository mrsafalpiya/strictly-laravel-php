<?php

namespace App\Pages\Auth;

use StrictlyLaravel\Inertia\Page;

final class LoginPage extends Page
{
    public function __construct(
        public bool $canResetPassword,
        public ?string $status,
    ) {}
}
