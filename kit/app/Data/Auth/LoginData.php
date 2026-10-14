<?php

namespace App\Data\Auth;

use StrictlyLaravel\Data\InputData;

/**
 * The payload of Fortify's login form. Fortify validates it; this class only types the form, which names it by hand
 * (`<TypedForm<LoginData>>`) until vendor payloads are declared (Problems P-025, P-100).
 */
final class LoginData extends InputData
{
    public function __construct(
        public string $email,
        public string $password,
        public bool $remember,
    ) {}
}
