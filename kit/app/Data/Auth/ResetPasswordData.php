<?php

namespace App\Data\Auth;

use StrictlyLaravel\Data\InputData;

/**
 * The payload of Fortify's password reset form (`token` and `email` are added by its `transform`), declared for its
 * action in config/strictly.php. Fortify validates it (App\Actions\Fortify\ResetUserPassword) and reads it; the
 * feature tests check that every key it does is a field here.
 */
final class ResetPasswordData extends InputData
{
    public function __construct(
        public string $token,
        public string $email,
        public string $password,
        public string $password_confirmation,
    ) {}
}
