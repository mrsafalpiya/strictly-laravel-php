<?php

namespace App\Data\Auth;

use Spatie\LaravelData\Optional;
use StrictlyLaravel\Data\InputData;

/**
 * The payload of Fortify's action enabling two-factor authentication, declared for it in config/strictly.php. The
 * feature tests check that every key Fortify reads is a field here.
 */
final class EnableTwoFactorData extends InputData
{
    public function __construct(
        /** Fortify generates a new secret even when the user has one with `$request->boolean('force')`. */
        public bool|Optional $force = new Optional,
    ) {}
}
