<?php

namespace App\Data\Auth;

use StrictlyLaravel\Data\InputData;

/**
 * The payload of Fortify's login form, declared for its action in config/strictly.php. Fortify validates and reads
 * it; the feature tests check that every key it does is a field here.
 */
final class LoginData extends InputData
{
    public function __construct(
        public string $email,
        public string $password,
        public bool $remember,
    ) {}
}
