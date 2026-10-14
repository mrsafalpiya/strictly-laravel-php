<?php

namespace App\Data\Auth;

use StrictlyLaravel\Data\InputData;

/**
 * The payload of Fortify's password reset form (`token` and `email` are added by its `transform`). Fortify validates
 * it (App\Actions\Fortify\ResetUserPassword); this class only types the form, which names it by hand
 * (`<TypedForm<ResetPasswordData>>`) until vendor payloads are declared (Problems P-025, P-100).
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
