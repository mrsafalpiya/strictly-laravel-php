<?php

namespace StrictlyLaravel\Inertia;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\Hidden;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * A Page class: the props of exactly one Inertia page (Problem P-009). Its constructor defines the props, and the page
 * component's name derives from the class name, mirroring resources/js/pages (PageLocations):
 *
 *     App\Pages\Settings\ProfilePage  →  'settings/profile'  →  resources/js/pages/settings/profile.tsx
 *
 * A controller (or a vendor view closure, or a route closure) returns the page, which is Responsable:
 *
 *     return new ProfilePage(mustVerifyEmail: true, status: null);
 *
 * and the page component receives exactly those props, typed by the generated `PageProps<'settings/profile'>`
 * (`@/generated`). `tsc` fails when the component file is missing or declares a prop the class doesn't send
 * (Problem P-010). `php artisan make:page settings/profile` creates both.
 */
#[Hidden]
abstract class Page extends Data
{
    /**
     * The page component's name, such as "settings/profile" (PageLocations).
     */
    public static function component(): string
    {
        return PageLocations::componentFor(static::class);
    }

    public function render(): Response
    {
        // @phpstan-ignore strictly.inertiaRender (the one render the rule sends apps to: the props are this Page class, which has a generated type)
        return Inertia::render(static::component(), $this);
    }

    /**
     * @param  Request  $request
     */
    public function toResponse($request): SymfonyResponse
    {
        return $this->render()->toResponse($request);
    }
}
