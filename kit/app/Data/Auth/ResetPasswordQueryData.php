<?php

namespace App\Data\Auth;

use StrictlyLaravel\Data\QueryData;

/**
 * The query string of Fortify's password reset link (`/reset-password/{token}?email=…`, sent in the reset email),
 * declared for its action in config/strictly.php: the reset password page reads `email` from it
 * (App\Providers\FortifyServiceProvider).
 */
final class ResetPasswordQueryData extends QueryData
{
    public function __construct(
        public string $email = '',
    ) {}
}
