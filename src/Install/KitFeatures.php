<?php

namespace StrictlyLaravel\Install;

/**
 * The auth features the React starter kit lets an app keep or cut out while `laravel new` runs (its `chisel.php`,
 * through `php artisan install:features`), under the names that script gives them. A Base Kit file can differ with
 * them, so Kit Conversion converts each file to the version for the app's features (Problem P-211).
 */
final class KitFeatures
{
    /** Every feature, in the order the starter kit asks about them. */
    public const array ALL = ['email-verification', 'registration', '2fa', 'passkeys', 'password-confirmation'];

    /** The Fortify feature (config/fortify.php's `features`) that each starter kit feature turns on, if any. */
    private const array FORTIFY = [
        'email-verification' => 'email-verification',
        'registration' => 'registration',
        '2fa' => 'two-factor-authentication',
        'passkeys' => 'passkeys',
    ];

    /**
     * The features the app at $root kept: the Fortify features its config/fortify.php turns on, and password
     * confirmation when its FortifyServiceProvider registers Fortify's confirm-password view. Password confirmation is
     * no Fortify feature (Fortify always registers its routes), and the kit's view is all that marks it.
     *
     * @return list<string> in the order of ALL
     */
    public static function detect(string $root): array
    {
        $config = "{$root}/config/fortify.php";
        $fortify = is_file($config) ? (require $config) : [];
        $enabled = is_array($fortify) && is_array($fortify['features'] ?? null) ? $fortify['features'] : [];
        $provider = "{$root}/app/Providers/FortifyServiceProvider.php";
        $confirmsPasswords = is_file($provider) && str_contains((string) file_get_contents($provider), 'Fortify::confirmPasswordView(');

        return array_values(array_filter(self::ALL, fn (string $feature): bool => $feature === 'password-confirmation'
            ? $confirmsPasswords
            : in_array(self::FORTIFY[$feature], $enabled, true)));
    }

    /**
     * The key of a feature set in kit-conversion.json: its features in the order of ALL, joined by commas, or "none".
     *
     * @param  list<string>  $features
     */
    public static function key(array $features): string
    {
        $ordered = array_values(array_intersect(self::ALL, $features));

        return $ordered === [] ? 'none' : implode(',', $ordered);
    }

    /**
     * Every subset of $features, each in the order of ALL.
     *
     * @param  list<string>  $features
     * @return list<list<string>>
     */
    public static function subsets(array $features): array
    {
        $subsets = [[]];

        foreach (array_values(array_intersect(self::ALL, $features)) as $feature) {
            foreach ($subsets as $subset) {
                $subsets[] = [...$subset, $feature];
            }
        }

        return $subsets;
    }
}
