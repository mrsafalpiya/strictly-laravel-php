<?php

namespace App\Data\Auth;

use Spatie\LaravelData\Optional;
use StrictlyLaravel\Data\InputData;

/**
 * The payload of Fortify's registration form, declared for its action in config/strictly.php. Fortify validates it
 * (App\Actions\Fortify\CreateNewUser) and reads it; the feature tests check that every key it does is a field here.
 */
final class RegisterData extends InputData
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
        public string $password_confirmation,
        /** Fortify logs the new user in with `$request->boolean('remember')`; the Base Kit's form doesn't send it. */
        public bool|Optional $remember = new Optional,
    ) {}
}
