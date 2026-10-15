<?php

namespace App\Data\Auth;

use StrictlyLaravel\Data\InputData;

/**
 * The payload of Fortify's form confirming two-factor authentication with a code from the authenticator app, declared
 * for its action in config/strictly.php. Fortify validates and reads it; the feature tests check that every key it
 * does is a field here.
 */
final class TwoFactorCodeData extends InputData
{
    public function __construct(
        public string $code,
    ) {}
}
