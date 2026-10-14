<?php

namespace StrictlyLaravel\Testing;

use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Support\Header;
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
 *     import type { Contract } from '@mrsafalpiya/strictly-laravel/contracts';
 *     import type { PageProps } from '@/generated';
 *
 *     export const props = { "members": [ … ] } satisfies Contract<PageProps<'members'>>;
 *
 * The props are the page's own props exactly as the browser receives them, decoded with `{}` and `[]` kept apart.
 * The shared props (the Inertia middleware's share step) are left out: they aren't the Page class's to type. A prop the
 * Page class declares is always kept, even when a shared prop has the same name. `satisfies` fails on a value of the
 * wrong type, a missing prop and a prop the type doesn't have, at any depth; a page component with no Page class fails
 * `PageProps`' constraint. `Contract<T>` is T, except that a map may arrive as `[]` (Problem P-076).
 *
 * Each Contract has a `.json` sidecar next to it describing what was recorded, for Contract Coverage to read.
 *
 * The directory is gitignored and type-checked by `tsc` (the tsconfig includes resources/js), which `ci:check` runs
 * after the tests. A test run that isn't parallel starts from an empty directory, so a Contract no test produces any more
 * disappears; a parallel run (`--parallel`) can't tell which process starts first, so it only adds and overwrites.
 */
final class ContractRecorder
{
    /** Relative to resources/js; gitignored by the installer. */
    public const string DIRECTORY = '__contracts__';

    private static bool $cleared = false;

    public static function directory(): string
    {
        return resource_path('js/'.self::DIRECTORY);
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
        }
    }

    /**
     * @param  array{component: string, props: stdClass}  $page
     */
    private static function recordPage(string $test, Request $request, array $page): void
    {
        $component = $page['component'];
        $props = self::ownProps($component, $page['props']);

        self::write($component, [
            sprintf('// Recorded by %s: %s %s', $test, $request->method(), $request->getRequestUri()),
            "import type { Contract } from '@mrsafalpiya/strictly-laravel/contracts';",
            "import type { PageProps } from '@/generated';",
            '',
            sprintf('export const props = %s satisfies Contract<PageProps<%s>>;', self::json($props), self::quote($component)),
        ], [
            'kind' => 'page',
            'test' => $test,
            'request' => $request->method().' '.$request->getRequestUri(),
            'component' => $component,
            'props' => $props,
        ]);
    }

    /**
     * The page's own props: every prop except the shared ones its Page class doesn't declare.
     */
    private static function ownProps(string $component, stdClass $props): stdClass
    {
        $declared = self::declaredProps($component);
        $shared = array_keys(Inertia::getShared());
        $own = new stdClass;

        foreach (get_object_vars($props) as $key => $value) {
            if (in_array($key, $declared, true) || ! in_array($key, $shared, true)) {
                $own->{$key} = $value;
            }
        }

        return $own;
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
     * view embeds on a first visit.
     *
     * @return array{component: string, props: stdClass}|null
     */
    private static function page(Response $response): ?array
    {
        $json = match (true) {
            $response->headers->get(Header::INERTIA) === 'true' => $response->getContent(),
            property_exists($response, 'original') && $response->original instanceof View => self::viewPage($response->original),
            default => false,
        };

        if (! is_string($json)) {
            return null;
        }

        try {
            $page = json_decode($json, false, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! $page instanceof stdClass || ! is_string($page->component ?? null)) {
            return null;
        }

        // No props at all is encoded as an empty list.
        $props = $page->props ?? null;

        return ['component' => $page->component, 'props' => $props instanceof stdClass ? $props : new stdClass];
    }

    private static function viewPage(View $view): string|false
    {
        $page = $view->getData()['page'] ?? null;

        return is_array($page) ? json_encode($page) : false;
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
