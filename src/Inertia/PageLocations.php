<?php

namespace StrictlyLaravel\Inertia;

use Illuminate\Support\Str;
use LogicException;

/**
 * Where a page lives, and the one place that maps between its names (Problems P-009, P-010): a Page class under
 * App\Pages, its file under app/Pages, its page component's name, and the component's file under resources/js/pages.
 *
 *     App\Pages\Settings\ProfilePage  ↔  app/Pages/Settings/ProfilePage.php
 *                                     ↔  'settings/profile'  ↔  resources/js/pages/settings/profile.tsx
 */
final class PageLocations
{
    public const string NAMESPACE = 'App\\Pages\\';

    public const string SUFFIX = 'Page';

    /** @var array<class-string, string> */
    private static array $components = [];

    /** Where the Page classes live: app/Pages. */
    public static function classesDirectory(): string
    {
        return app_path('Pages');
    }

    /** Where the page components live: resources/js/pages. */
    public static function componentsDirectory(): string
    {
        return resource_path('js/pages');
    }

    /**
     * The Page class for a page component's name: each segment in studly case under App\Pages, with the Page suffix.
     *
     * @return class-string
     */
    public static function classFor(string $component): string
    {
        /** @var class-string */
        return self::NAMESPACE.implode('\\', array_map(Str::studly(...), explode('/', $component))).self::SUFFIX;
    }

    /**
     * The page component's name for a Page class: its path under App\Pages without the Page suffix, each segment in
     * kebab case. Throws for a class outside App\Pages or without the suffix.
     *
     * @param  class-string  $class
     */
    public static function componentFor(string $class): string
    {
        return self::$components[$class] ??= self::resolveComponent($class);
    }

    /**
     * The file a Page class lives in, such as app/Pages/Settings/ProfilePage.php.
     *
     * @param  class-string  $class
     */
    public static function classFile(string $class): string
    {
        return self::classesDirectory().'/'.str_replace('\\', '/', Str::after($class, self::NAMESPACE)).'.php';
    }

    /** The file a page component lives in, such as resources/js/pages/settings/profile.tsx. */
    public static function componentFile(string $component): string
    {
        return self::componentsDirectory()."/{$component}.tsx";
    }

    /**
     * @param  class-string  $class
     */
    private static function resolveComponent(string $class): string
    {
        $segments = explode('\\', substr($class, strlen(self::NAMESPACE), -strlen(self::SUFFIX)));

        if (! str_starts_with($class, self::NAMESPACE) || ! str_ends_with($class, self::SUFFIX) || in_array('', $segments, true)) {
            throw new LogicException(sprintf(
                'A Page class must live in the %s namespace and its name must end with "%s", like %sSettings\\ProfilePage; %s does not.',
                self::NAMESPACE, self::SUFFIX, self::NAMESPACE, $class,
            ));
        }

        return implode('/', array_map(Str::kebab(...), $segments));
    }
}
