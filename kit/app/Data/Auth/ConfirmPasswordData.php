<?php

namespace App\Data\Auth;

use StrictlyLaravel\Data\InputData;

/**
 * The payload of Fortify's password confirmation form. Fortify validates it; this class only types the form, which
 * names it by hand (`<TypedForm<ConfirmPasswordData>>`) until vendor payloads are declared (Problems P-025, P-100).
 */
final class ConfirmPasswordData extends InputData
{
    public function __construct(
        public string $password,
    ) {}
}
