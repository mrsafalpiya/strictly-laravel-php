<?php

namespace StrictlyLaravel\Inertia;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\Hidden;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * A Page class: the props of exactly one Inertia page (Problem P-009). Its constructor defines the props, and the page
 * component's name derives from the class name, mirroring resources/js/pages:
 *
 *     App\Pages\Settings\ProfilePage  →  'settings/profile'  →  resources/js/pages/settings/profile.tsx
 *
 * A controller (or a vendor view closure, or a route closure) returns the page, which is Responsable:
 *
 *     return new ProfilePage(mustVerifyEmail: true, status: null);
 *
 * and the page component receives exactly those props, typed by the generated `PageProps<'settings/profile'>`
 * (`@/generated`). `tsc` fails when the component file is missing or asks for a prop the class doesn't send
 * (Problem P-010). `php artisan make:page settings/profile` creates both.
 */
#[Hidden]
abstract class Page extends Data
{
    public const string NAMESPACE = 'App\\Pages\\';

    public const string SUFFIX = 'Page';

    /** @var array<class-string, string> */
    private static array $components = [];

    /**
     * The page component's name, such as "settings/profile".
     */
    public static function component(): string
    {
        return self::componentFor(static::class);
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
     * The name of the Page class's generated TypeScript type, such as "SettingsProfilePage": unique per page, where
     * the class's short name (ProfilePage, ShowPage) is not.
     *
     * @param  class-string  $class
     */
    public static function typeScriptNameFor(string $class): string
    {
        return Str::of(self::componentFor($class))
            ->explode('/')
            ->map(fn (string $segment): string => Str::studly($segment))
            ->implode('').self::SUFFIX;
    }

    public function render(): Response
    {
        return Inertia::render(static::component(), $this);
    }

    /**
     * @param  Request  $request
     */
    public function toResponse($request): SymfonyResponse
    {
        return $this->render()->toResponse($request);
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
