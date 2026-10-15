<?php

namespace App\Data\Auth;

use StrictlyLaravel\Data\InputData;

/**
 * The payload of Fortify's two-factor challenge form: a code from the authenticator app, or a recovery code. Declared
 * for its action in config/strictly.php; Fortify validates and reads it, and the feature tests check that every key it
 * does is a field here.
 */
final class TwoFactorChallengeData extends InputData
{
    public function __construct(
        public ?string $code,
        public ?string $recovery_code,
    ) {}
}
