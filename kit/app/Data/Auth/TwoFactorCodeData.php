<?php

namespace App\Data\Auth;

use StrictlyLaravel\Data\InputData;

/**
 * The payload of Fortify's form confirming two-factor authentication with a code from the authenticator app. Fortify
 * validates it; this class only types the form, which names it by hand (`<TypedForm<TwoFactorCodeData>>`) until vendor
 * payloads are declared (Problems P-025, P-100).
 */
final class TwoFactorCodeData extends InputData
{
    public function __construct(
        public string $code,
    ) {}
}
