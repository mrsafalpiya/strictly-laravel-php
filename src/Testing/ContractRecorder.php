<?php

namespace StrictlyLaravel\Testing;

use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Support\Header;
use Inertia\Support\SessionKey;
use JsonException;
use Spatie\LaravelData\Support\DataConfig;
use Spatie\LaravelData\Support\DataProperty;
use stdClass;
use StrictlyLaravel\Inertia\Page;
use StrictlyLaravel\Inertia\PageLocations;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records what the server really sent during a feature test as a Contract that `tsc` checks against the generated type
 * (Problem P-065). No test needs extra code: the installer's Pest hook (RecordsContracts) starts the recorder before
 * every feature test, and every request the test makes is inspected as it is handled.
 *
 * A page render becomes resources/js/__contracts__/<page component>/<hash>.ts:
 *
 *     // Recorded by Tests\Feature\MembersPageTest > the members page lists every member: GET /members
 *     import type { FlashData, SharedPageProps } from '@inertiajs/core';
 *     import type { Contract } from '@mrsafalpiya/strictly-laravel/contracts';
 *     import type { PageProps } from '@/generated';
 *
 *     export const props = { "members": [ … ] } satisfies Contract<PageProps<'members'>>;
 *     export const shared = { "name": "Laravel", "auth": { "user": null }, … } satisfies Contract<SharedPageProps>;
 *     export const flash = { "toast": { … } } satisfies Contract<FlashData>;
 *
 * `props` are the page's own props exactly as the browser receives them, decoded with `{}` and `[]` kept apart.
 * `shared` are the shared props (the Inertia middleware's share step, except Inertia's own `errors`), checked against
 * Inertia's global config typing, which `strictly:generate` writes from the shared props Data class (Problems P-012,
 * P-088). A prop the Page class declares is always the page's own, even when a shared prop has the same name. `flash`
 * is what the response flashed, when it flashed anything (Problem P-047). A partial reload records no `shared`, and a
 * page rendered without the Inertia middleware, which shares nothing, records none either.
 *
 * A partial reload (Problem P-046) sends only the props it asked for, so its `props` are checked as
 * `Contract<PartialReload<PageProps<'reports'>, 'stats'>>`: each requested prop of the page must be there, even one the
 * page's type marks optional (a deferred prop), and a requested name the page doesn't have fails `tsc`.
 *
 * Flash data set before a redirect reaches the browser with the next page, so a redirect that flashes records it on
 * its own, as resources/js/__contracts__/_flash/<hash>.ts with `export const flash = … satisfies Contract<FlashData>`.
 *
 * `satisfies` fails on a value of the wrong type, a missing key and a key the type doesn't have, at any depth; a page
 * component with no Page class fails `PageProps`' constraint. `Contract<T>` is T, except that a map may arrive as `[]`
 * (Problem P-076).
 *
 * Each Contract has a `.json` sidecar next to it describing what was recorded (`kind` is `page` or `flash`; a partial reload's page record has `partial`), which
 * Contract Coverage reads (records(), `strictly:coverage`).
 *
 * The directory is gitignored and type-checked by `tsc` (the tsconfig includes resources/js), which `ci:check` runs
 * after the tests. A test run that isn't parallel starts from an empty directory, so a Contract no test produces any more
 * disappears; a parallel run (`--parallel`) can't tell which process starts first, so it only adds and overwrites.
 */
final class ContractRecorder
{
    /** Relative to resources/js; gitignored by the installer. */
    public const string DIRECTORY = '__contracts__';

    /** Where the flash data of redirects is recorded, under DIRECTORY. */
    private const string FLASH_DIRECTORY = '_flash';

    /**
     * The shared props Inertia types itself rather than through the shared props Data class: the validation errors
     * (`Page['props']['errors']`), shared by Inertia's middleware.
     */
    private const array INERTIA_SHARED_PROPS = ['errors'];

    private static bool $cleared = false;

    public static function directory(): string
    {
        return resource_path('js/'.self::DIRECTORY);
    }

    /**
     * Every recorded Contract's sidecar, decoded with objects as stdClass (so `{}` and `[]` stay apart), in no
     * particular order: what Contract Coverage reads. Each has a `kind` (`page` or `flash`) and the payload it recorded.
     *
     * @return list<stdClass>
     */
    public static function records(): array
    {
        $directory = self::directory();

        if (! File::isDirectory($directory)) {
            return [];
        }

        $records = [];

        foreach (File::allFiles($directory) as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }

            try {
                $record = json_decode($file->getContents(), false, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue; // a sidecar a parallel test process is still writing
            }

            if ($record instanceof stdClass && is_string($record->kind ?? null)) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * Record every request handled from now on in this application, attributed to the named test.
     */
    public static function listen(string $test): void
    {
        if (! self::$cleared && getenv('TEST_TOKEN') === false) {
            File::deleteDirectory(self::directory());
        }

        self::$cleared = true;

        Event::listen(RequestHandled::class, function (RequestHandled $event) use ($test): void {
            self::record($test, $event->request, $event->response);
        });
    }

    private static function record(string $test, Request $request, Response $response): void
    {
        $page = self::page($response);

        if ($page !== null) {
            self::recordPage($test, $request, $page);
        } elseif ($response->isRedirection()) {
            self::recordRedirectFlash($test, $request);
        }
    }

    /**
     * @param  array{component: string, props: stdClass, sharedKeys: list<string>, flash: stdClass|null}  $page
     */
    private static function recordPage(string $test, Request $request, array $page): void
    {
        $component = $page['component'];
        $declared = self::declaredProps($component);
        $sharedKeys = array_values(array_diff($page['sharedKeys'], self::INERTIA_SHARED_PROPS));
        $props = new stdClass;
        $shared = new stdClass;

        foreach (get_object_vars($page['props']) as $key => $value) {
            if (in_array($key, $declared, true) || ! in_array($key, $page['sharedKeys'], true)) {
                $props->{$key} = $value;
            } elseif (in_array($key, $sharedKeys, true)) {
                $shared->{$key} = $value;
            }
        }

        $partial = self::partialReload($request, $component, $declared, $page['sharedKeys']);

        // A partial reload sends only the props it asked for, and a page rendered without the Inertia middleware
        // shares nothing: neither shows what the shared props are.
        $recordShared = $sharedKeys !== [] && $partial === null;
        $overridden = array_values(array_intersect($sharedKeys, $declared));
        $sharedType = $overridden === []
            ? 'SharedPageProps'
            : 'Omit<SharedPageProps, '.implode(' | ', array_map(self::quote(...), $overridden)).'>';

        $coreTypes = array_merge($recordShared ? ['SharedPageProps'] : [], $page['flash'] !== null ? ['FlashData'] : []);
        sort($coreTypes);

        self::write($component, [
            sprintf('// Recorded by %s: %s %s%s', $test, $request->method(), $request->getRequestUri(), self::describePartial($partial)),
            ...$coreTypes === [] ? [] : [sprintf("import type { %s } from '@inertiajs/core';", implode(', ', $coreTypes))],
            sprintf("import type { %s } from '@mrsafalpiya/strictly-laravel/contracts';", $partial === null ? 'Contract' : 'Contract, PartialReload'),
            "import type { PageProps } from '@/generated';",
            '',
            sprintf('export const props = %s satisfies Contract<%s>;', self::json($props), $partial === null
                ? 'PageProps<'.self::quote($component).'>'
                : 'PartialReload<PageProps<'.self::quote($component).'>, '.($partial['only'] === [] ? 'never' : implode(' | ', array_map(self::quote(...), $partial['only']))).'>'),
            ...$recordShared ? [sprintf('export const shared = %s satisfies Contract<%s>;', self::json($shared), $sharedType)] : [],
            ...$page['flash'] !== null ? [sprintf('export const flash = %s satisfies Contract<FlashData>;', self::json($page['flash']))] : [],
        ], [
            'kind' => 'page',
            'test' => $test,
            'request' => $request->method().' '.$request->getRequestUri(),
            'component' => $component,
            'partial' => $partial,
            'props' => $props,
            'shared' => $recordShared ? $shared : null,
            'flash' => $page['flash'],
        ]);
    }

    /**
     * The page's own props a partial reload of the component asked for (Problem P-046), or null when the request isn't
     * one: Inertia reloads only when the partial component it names is the one it renders. `only` keeps the requested
     * names (dotted ones too, `project.stats`) whose first segment is the page's own, as ownership is decided for the
     * props; `except` is what it left out.
     *
     * @param  list<string>  $declared  the props the Page class declares
     * @param  list<string>  $sharedKeys  the shared props
     * @return array{only: list<string>, except: list<string>}|null
     */
    private static function partialReload(Request $request, string $component, array $declared, array $sharedKeys): ?array
    {
        if ($request->header(Header::PARTIAL_COMPONENT) !== $component) {
            return null;
        }

        $only = array_values(array_filter(self::headerList($request, Header::PARTIAL_ONLY), function (string $key) use ($declared, $sharedKeys): bool {
            $head = explode('.', $key, 2)[0];

            return in_array($head, $declared, true) || ! in_array($head, $sharedKeys, true);
        }));

        return ['only' => $only, 'except' => self::headerList($request, Header::PARTIAL_EXCEPT)];
    }

    /**
     * @param  array{only: list<string>, except: list<string>}|null  $partial
     */
    private static function describePartial(?array $partial): string
    {
        if ($partial === null) {
            return '';
        }

        $parts = array_filter([
            $partial['only'] === [] ? null : 'only '.implode(', ', $partial['only']),
            $partial['except'] === [] ? null : 'except '.implode(', ', $partial['except']),
        ]);

        return ' (partial reload'.($parts === [] ? '' : ': '.implode('; ', $parts)).')';
    }

    /**
     * A comma-separated request header, as Inertia reads it.
     *
     * @return list<string>
     */
    private static function headerList(Request $request, string $header): array
    {
        $value = $request->header($header);

        return is_string($value) ? array_values(array_unique(array_filter(explode(',', $value), fn (string $key): bool => $key !== ''))) : [];
    }

    /**
     * Flash data set before a redirect is sent with the next page, which the test may never request: record it here.
     */
    private static function recordRedirectFlash(string $test, Request $request): void
    {
        $flashed = $request->hasSession() ? $request->session()->get(SessionKey::FLASH_DATA) : null;
        $flash = is_array($flashed) && $flashed !== [] ? self::decode(self::json($flashed)) : null;

        if (! $flash instanceof stdClass) {
            return;
        }

        self::write(self::FLASH_DIRECTORY, [
            sprintf('// Recorded by %s: %s %s (redirect)', $test, $request->method(), $request->getRequestUri()),
            "import type { FlashData } from '@inertiajs/core';",
            "import type { Contract } from '@mrsafalpiya/strictly-laravel/contracts';",
            '',
            sprintf('export const flash = %s satisfies Contract<FlashData>;', self::json($flash)),
        ], [
            'kind' => 'flash',
            'test' => $test,
            'request' => $request->method().' '.$request->getRequestUri(),
            'flash' => $flash,
        ]);
    }

    /**
     * The names of the props the component's Page class sends, or none when it has no Page class.
     *
     * @return list<string>
     */
    private static function declaredProps(string $component): array
    {
        $class = PageLocations::classFor($component);

        if (! class_exists($class) || ! is_subclass_of($class, Page::class)) {
            return [];
        }

        return app(DataConfig::class)->getDataClass($class)->properties
            ->reject(fn (DataProperty $property): bool => $property->hidden)
            ->map(fn (DataProperty $property): string => $property->outputMappedName ?? $property->name)
            ->values()
            ->all();
    }

    /**
     * The page object exactly as the browser receives it: the JSON body of an Inertia request, or the page the root
     * view embeds on a first visit. `sharedKeys` are the props the Inertia middleware shared, which the page lists
     * unless the app turned that off (`inertia.expose_shared_prop_keys`).
     *
     * @return array{component: string, props: stdClass, sharedKeys: list<string>, flash: stdClass|null}|null
     */
    private static function page(Response $response): ?array
    {
        $json = match (true) {
            $response->headers->get(Header::INERTIA) === 'true' => $response->getContent(),
            property_exists($response, 'original') && $response->original instanceof View => self::viewPage($response->original),
            default => false,
        };

        $page = is_string($json) ? self::decode($json) : null;

        if (! $page instanceof stdClass || ! is_string($page->component ?? null)) {
            return null;
        }

        // No props at all is encoded as an empty list.
        $props = $page->props ?? null;
        $sharedKeys = $page->sharedProps ?? array_keys(Inertia::getShared());
        $flash = $page->flash ?? null;

        return [
            'component' => $page->component,
            'props' => $props instanceof stdClass ? $props : new stdClass,
            'sharedKeys' => is_array($sharedKeys) ? array_values(array_filter($sharedKeys, is_string(...))) : [],
            'flash' => $flash instanceof stdClass ? $flash : null,
        ];
    }

    private static function viewPage(View $view): string|false
    {
        $page = $view->getData()['page'] ?? null;

        return is_array($page) ? json_encode($page) : false;
    }

    /** JSON decoded with objects kept as objects, so `{}` and `[]` stay apart; null when it isn't JSON. */
    private static function decode(string $json): mixed
    {
        try {
            return json_decode($json, false, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, mixed>  $meta
     */
    private static function write(string $directory, array $lines, array $meta): void
    {
        $source = implode("\n", $lines)."\n";
        $path = self::directory().'/'.$directory.'/'.substr(sha1($source), 0, 12);

        // Parallel test processes create the same directories: losing that race is fine.
        if (! File::isDirectory(dirname($path))) {
            File::makeDirectory(dirname($path), 0755, recursive: true, force: true);
        }
        File::put($path.'.ts', $source);
        File::put($path.'.json', self::json($meta)."\n");
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function quote(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }
}
