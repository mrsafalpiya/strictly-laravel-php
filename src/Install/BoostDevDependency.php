<?php

namespace StrictlyLaravel\Install;

use JsonException;
use RuntimeException;

/**
 * Keeps Laravel Boost out of production vendor/ (Problem P-204). Boost installs the Strictly Laravel guidance for AI
 * coding agents (P-146), but the app requires the Composer package as a production dependency, so the package can't
 * require Boost without installing it in production too, where an app misconfigured with APP_DEBUG on would serve Boost's
 * browser-log route and inject its script into every page. The app requires Boost as a dev dependency instead, as
 * `laravel new` does. Only the developer can run Composer, so this changes nothing: it throws until composer.json lists
 * laravel/boost under `require-dev` and not under `require`.
 */
final class BoostDevDependency
{
    public const string PACKAGE = 'laravel/boost';

    public static function check(string $composerJson): bool
    {
        $contents = file_get_contents($composerJson);

        if ($contents === false) {
            throw new RuntimeException("Cannot read {$composerJson}");
        }

        try {
            $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("{$composerJson} is not valid JSON: {$e->getMessage()}", previous: $e);
        }

        if (! is_array($manifest)) {
            throw new RuntimeException("{$composerJson} is not a JSON object");
        }

        $package = self::PACKAGE;

        if (self::requires($manifest, 'require')) {
            throw new RuntimeException("composer.json requires {$package} in `require`, so it is installed in production: "
                ."move it to `require-dev`, then run `composer update {$package}`.");
        }

        if (! self::requires($manifest, 'require-dev')) {
            throw new RuntimeException("composer.json does not require {$package}: run `composer require {$package} --dev`, "
                .'then `php artisan boost:install`, so AI coding agents get the Strictly Laravel guidance.');
        }

        return false;
    }

    /** @param  array<mixed>  $manifest */
    private static function requires(array $manifest, string $section): bool
    {
        $requirements = $manifest[$section] ?? [];

        return is_array($requirements) && array_key_exists(self::PACKAGE, $requirements);
    }
}
