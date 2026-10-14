<?php

namespace App\Data;

use Spatie\LaravelData\Data;

final class AuthData extends Data
{
    public function __construct(
        /** Null for guests: on pages behind the `auth` middleware, read it with `useUser()`. */
        public ?UserData $user,
    ) {}
}
