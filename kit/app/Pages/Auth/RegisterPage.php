<?php

namespace App\Pages\Auth;

use StrictlyLaravel\Inertia\Page;

final class RegisterPage extends Page
{
    public function __construct(
        public string $passwordRules,
    ) {}
}
