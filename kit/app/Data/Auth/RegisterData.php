<?php

namespace App\Data\Auth;

use StrictlyLaravel\Data\InputData;

/**
 * The payload of Fortify's registration form. Fortify validates it (App\Actions\Fortify\CreateNewUser); this class only
 * types the form, which names it by hand (`<TypedForm<RegisterData>>`) until vendor payloads are declared (Problems
 * P-025, P-100).
 */
final class RegisterData extends InputData
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
        public string $password_confirmation,
    ) {}
}
