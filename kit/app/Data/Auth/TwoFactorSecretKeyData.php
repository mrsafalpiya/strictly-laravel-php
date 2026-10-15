<?php

namespace App\Data\Auth;

use Spatie\LaravelData\Data;

/**
 * The response of Fortify's two-factor secret key endpoint, declared for it in config/strictly.php, so
 * `useRouteHttp(secretKey())` types its result. The feature tests check every response against it.
 */
final class TwoFactorSecretKeyData extends Data
{
    public function __construct(
        public string $secretKey,
    ) {}
}
