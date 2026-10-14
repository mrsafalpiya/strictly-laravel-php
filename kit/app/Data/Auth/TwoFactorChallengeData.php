<?php

namespace App\Data\Auth;

use StrictlyLaravel\Data\InputData;

/**
 * The payload of Fortify's two-factor challenge form: a code from the authenticator app, or a recovery code. Fortify
 * validates it; this class only types the form, which names it by hand (`<TypedForm<TwoFactorChallengeData>>`) until
 * vendor payloads are declared (Problems P-025, P-100).
 */
final class TwoFactorChallengeData extends InputData
{
    public function __construct(
        public ?string $code,
        public ?string $recovery_code,
    ) {}
}
