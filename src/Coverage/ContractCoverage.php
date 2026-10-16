<?php

namespace StrictlyLaravel\Coverage;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\DataConfig;
use stdClass;
use StrictlyLaravel\Broadcasting\BroadcastEvent;
use StrictlyLaravel\Inertia\GlobalDataClasses;
use StrictlyLaravel\Inertia\Page;
use StrictlyLaravel\Routes\RouteResponse;

/**
 * Contract Coverage (Problem P-066): what the recorded Contracts never exercised. `tsc` proves a generated type only
 * for the payloads the feature tests produced, so a page no test renders, or a state its type permits that no
 * Contract showed (ShapeCoverage), was never checked at all.
 *
 * Each dimension reads the Contract records of its kind:
 *
 * - pages: every Page class must be rendered by some test (`page` records), and its props' shapes are observed.
 * - shared props: some test must render a page with them (`page` records' `shared`), and their shapes are observed
 *   against the shared props Data class.
 * - deferred props: every lazy prop of a Page class must be loaded by some test (a partial reload, which is how
 *   Inertia loads a deferred prop, records the page with it), or its type was never checked.
 * - flash: what was flashed, with a page (`page` records' `flash`) or before a redirect (`flash` records), is observed
 *   against the flash Data class, so a key no test flashes is a gap.
 * - form endpoints: some test must send a request to every route whose action takes an input Data class (`form`
 *   records), or the validation errors it can return were never checked against the input's fields.
 *
 * - JSON endpoints: some test must get a successful response from every route whose action returns a Data class, or
 *   whose vendor action has a declared response (`json` records), and the responses' shapes are observed against a
 *   Data class (a response declared as a TypeScript type has none to observe, Problem P-051).
 * - declared vendor fields: every field config/strictly.php declares for a vendor action's input or query must be
 *   one some test saw the vendor validate, read or report an error for (`form` and `query` records), and a declared
 *   vendor query must be requested by some test.
 * - broadcasts: some test must broadcast every event whose payload is a Data class (`broadcast` records), or its
 *   payload type was never checked, and the payloads' shapes are observed against that class. Some test must also
 *   broadcast it on every channel its #[BroadcastsOn] declares (the records' `patterns`), or the declaration may be
 *   stale and its listeners never hear it (Problem P-144). A public channel config/strictly.php declares must be one
 *   some event's #[BroadcastsOn] names, since listeners are all it is declared for.
 *
 * A Type Boundary that records Contracts of a new kind adds its dimension here: an unexercised Gap for what no test
 * reached, and its Data values fed to the same ShapeCoverage so their shapes are proven too.
 */
final readonly class ContractCoverage
{
    public function __construct(
        private DataConfig $config,
    ) {}

    /**
     * Every gap, the unexercised ones first.
     *
     * @param  list<stdClass>  $records  the Contract sidecars (ContractRecorder::records())
     * @param  array<string, class-string<Page>>  $pages  page component => Page class (PageLocations::pages())
     * @param  GlobalDataClasses|null  $global  the shared props and flash Data classes (GlobalDataClasses::in())
     * @param  array<string, class-string>  $formEndpoints  endpoint => input Data class (FormEndpoints::all())
     * @param  array<string, RouteResponse>  $jsonEndpoints  endpoint => response (JsonEndpoints::all())
     * @param  list<array{action: string, kind: 'input'|'query', class: class-string, endpoints: list<string>}>  $vendorPayloads  the declared vendor inputs and queries (VendorDeclarations::payloads())
     * @param  array<string, BroadcastEvent>  $broadcasts  the broadcast events by the name Echo listens for them (BroadcastEvents::in())
     * @param  list<string>  $publicChannels  the patterns of the public channels config/strictly.php declares (PublicChannels::declared())
     * @return list<Gap>
     */
    public function gaps(array $records, array $pages, ?GlobalDataClasses $global = null, array $formEndpoints = [], array $jsonEndpoints = [], array $vendorPayloads = [], array $broadcasts = [], array $publicChannels = []): array
    {
        $shapes = new ShapeCoverage($this->config);
        $unexercised = [
            ...$this->pages($records, $pages, $shapes),
            ...$this->shared($records, $global?->shared, $shapes),
            ...$this->flash($records, $global?->flash, $shapes),
            ...$this->formEndpoints($records, $formEndpoints),
            ...$this->jsonEndpoints($records, $jsonEndpoints, $shapes),
            ...$this->vendorFields($records, $vendorPayloads),
            ...$this->broadcasts($records, $broadcasts, $shapes),
            ...$this->publicChannels($broadcasts, $publicChannels),
        ];

        $this->accepted($records, $shapes);

        return [...$unexercised, ...$shapes->gaps()];
    }

    /**
     * Problem P-125: what form endpoints accepted (`form` records' `accepted`) shows the states of their input
     * classes, which pages also send as a form's defaults, empty on a create page by design.
     *
     * @param  list<stdClass>  $records
     */
    private function accepted(array $records, ShapeCoverage $shapes): void
    {
        $accepted = [];

        foreach ($records as $record) {
            $input = $record->input ?? null;

            if ($record->kind === 'form' && ($record->accepted ?? null) instanceof stdClass && is_string($input) && is_subclass_of($input, Data::class)) {
                $accepted[$input][(string) ($record->endpoint ?? '')][] = $record->accepted;
            }
        }

        foreach ($accepted as $input => $endpoints) {
            foreach ($endpoints as $endpoint => $values) {
                $shapes->observeAccepted($input, $values, new Origin($endpoint, "submits to {$endpoint}"));
            }
        }
    }

    /**
     * Problem P-025: a field config/strictly.php declares for a vendor action must be one the vendor uses, or the
     * declaration types a form with a field the server ignores. A field is used when some test saw the vendor validate
     * it, read it or report an error for it (the `form` and `query` records of the action's routes). An input whose
     * endpoint no test submits to is already a form endpoint gap.
     *
     * @param  list<stdClass>  $records
     * @param  list<array{action: string, kind: 'input'|'query', class: class-string, endpoints: list<string>}>  $payloads
     * @return list<Gap>
     */
    private function vendorFields(array $records, array $payloads): array
    {
        $used = [];

        foreach ($records as $record) {
            if (! in_array($record->kind, ['form', 'query'], true) || ! is_string($record->endpoint ?? null)) {
                continue;
            }

            $keys = [...(array) ($record->validated ?? []), ...(array) ($record->read ?? []), ...(array) ($record->errors ?? [])];
            $used[$record->kind][$record->endpoint] ??= [];

            foreach ($keys as $key) {
                if (is_string($key)) {
                    $used[$record->kind][$record->endpoint][explode('.', $key, 2)[0]] = true;
                }
            }
        }

        $gaps = [];

        foreach ($payloads as $payload) {
            $kind = $payload['kind'] === 'input' ? 'form' : 'query';
            $requested = array_values(array_filter($payload['endpoints'], fn (string $endpoint): bool => isset($used[$kind][$endpoint])));
            $endpoints = implode(', ', $payload['endpoints']);

            if ($requested === []) {
                if ($kind === 'query' && $payload['endpoints'] !== []) {
                    $gaps[] = new Gap(
                        "{$endpoints}: no feature test requests this vendor route",
                        [],
                        "add a feature test that requests {$endpoints} with the query string {$payload['class']} declares",
                        unexercised: true,
                    );
                }

                continue;
            }

            $seen = [];

            foreach ($requested as $endpoint) {
                $seen += $used[$kind][$endpoint];
            }

            foreach ($this->config->getDataClass($payload['class'])->properties as $property) {
                $name = $property->inputMappedName ?? $property->name;

                if (! isset($seen[$name])) {
                    $gaps[] = new Gap(
                        sprintf('%s.%s is declared for a vendor action that never uses it', class_basename($payload['class']), $name),
                        $requested,
                        sprintf(
                            'add a feature test that %s %s with %s, or remove it from the declaration in config/strictly.php if %s doesn\'t validate or read it',
                            $kind === 'form' ? 'submits to' : 'requests',
                            implode(', ', $requested),
                            $name,
                            $payload['action'],
                        ),
                        unexercised: true,
                    );
                }
            }
        }

        return $gaps;
    }

    /**
     * @param  list<stdClass>  $records
     * @param  class-string|null  $class
     * @return list<Gap>
     */
    private function shared(array $records, ?string $class, ShapeCoverage $shapes): array
    {
        if ($class === null) {
            return [];
        }

        $values = [];

        foreach ($records as $record) {
            if ($record->kind === 'page' && ($record->shared ?? null) instanceof stdClass) {
                $values[] = $record->shared;
            }
        }

        if ($values === []) {
            return [new Gap(
                'shared props: no feature test renders a page through the Inertia middleware',
                [],
                'add a feature test that requests a page route in the web middleware group',
                unexercised: true,
            )];
        }

        $shapes->observe($class, $values, new Origin('shared props', 'renders a page'));

        return [];
    }

    /**
     * @param  list<stdClass>  $records
     * @param  class-string|null  $class
     * @return list<Gap>
     */
    private function flash(array $records, ?string $class, ShapeCoverage $shapes): array
    {
        if ($class === null) {
            return [];
        }

        $values = [];

        foreach ($records as $record) {
            if (in_array($record->kind, ['page', 'flash'], true) && ($record->flash ?? null) instanceof stdClass) {
                $values[] = $record->flash;
            }
        }

        // An empty object stands for every response that flashed nothing, so each key the class declares is
        // reported as never sent until some test flashes it.
        $shapes->observe($class, [...$values, new stdClass], new Origin('flash data', 'flashes data'));

        return [];
    }

    /**
     * The props of a Page class that are absent until the page loads them (Problem P-043): the laravel-data lazies
     * (`Lazy|ReportStatsData` built with `Lazy::inertiaDeferred()`, `Lazy::inertia()` and the like), by output name.
     *
     * @param  class-string<Page>  $class
     * @return list<string>
     */
    private function deferredProps(string $class): array
    {
        $props = [];

        foreach ($this->config->getDataClass($class)->properties as $property) {
            if (! $property->hidden && $property->type->lazyType !== null) {
                $props[] = $property->outputMappedName ?? $property->name;
            }
        }

        return $props;
    }

    /**
     * @param  list<mixed>  $values
     */
    private function anyHas(array $values, string $prop): bool
    {
        foreach ($values as $value) {
            if ($value instanceof stdClass && property_exists($value, $prop)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every page record counts, a partial reload's too (Problem P-046): it is how a deferred prop is loaded.
     *
     * @param  list<stdClass>  $records
     * @param  array<string, class-string<Page>>  $pages
     * @return list<Gap>
     */
    private function pages(array $records, array $pages, ShapeCoverage $shapes): array
    {
        $rendered = [];

        foreach ($records as $record) {
            if ($record->kind === 'page' && is_string($record->component ?? null)) {
                $rendered[$record->component][] = $record->props ?? null;
            }
        }

        $gaps = [];

        foreach ($pages as $component => $class) {
            if (! isset($rendered[$component])) {
                $gaps[] = new Gap(
                    "{$component}: no feature test renders this page",
                    [],
                    "add a feature test that requests a route returning {$class}",
                    unexercised: true,
                );

                continue;
            }

            $deferred = $this->deferredProps($class);
            $shapes->observe($class, $rendered[$component], Origin::page($component), absenceReported: $deferred);

            foreach ($deferred as $prop) {
                if (! $this->anyHas($rendered[$component], $prop)) {
                    $gaps[] = new Gap(
                        "{$component} › {$prop}: no feature test loads this deferred prop",
                        [],
                        "add a feature test that renders {$component} and loads {$prop} (`\$page->loadDeferredProps()` in `assertInertia()`, or a partial reload)",
                        unexercised: true,
                    );
                }
            }
        }

        return $gaps;
    }

    /**
     * @param  list<stdClass>  $records
     * @param  array<string, class-string>  $endpoints
     * @return list<Gap>
     */
    private function formEndpoints(array $records, array $endpoints): array
    {
        $submitted = [];

        foreach ($records as $record) {
            if ($record->kind === 'form' && is_string($record->endpoint ?? null)) {
                $submitted[$record->endpoint] = true;
            }
        }

        $gaps = [];

        foreach ($endpoints as $endpoint => $input) {
            if (! isset($submitted[$endpoint])) {
                $gaps[] = new Gap(
                    "{$endpoint}: no feature test submits to this form endpoint",
                    [],
                    "add a feature test that sends {$input} to {$endpoint}",
                    unexercised: true,
                );
            }
        }

        return $gaps;
    }

    /**
     * @param  list<stdClass>  $records
     * @param  array<string, RouteResponse>  $endpoints
     * @return list<Gap>
     */
    private function jsonEndpoints(array $records, array $endpoints, ShapeCoverage $shapes): array
    {
        $responses = [];

        foreach ($records as $record) {
            if ($record->kind === 'json' && is_string($record->endpoint ?? null)) {
                $responses[$record->endpoint][] = $record->body ?? null;
            }
        }

        $gaps = [];

        foreach ($endpoints as $endpoint => $response) {
            $dataClass = $response->dataClass();

            if (! isset($responses[$endpoint])) {
                $gaps[] = new Gap(
                    "{$endpoint}: no feature test calls this JSON endpoint",
                    [],
                    sprintf('add a feature test that calls %s and gets a successful %s response', $endpoint, $dataClass ?? "`{$response->declared}`"),
                    unexercised: true,
                );

                continue;
            }

            // A vendor response declared as a TypeScript type has no shapes to observe (Problem P-051). A generic Data
            // class is walked with the type arguments of the action's `@return` (Problem P-197).
            if ($dataClass !== null) {
                $shapes->observe($dataClass, $responses[$endpoint], new Origin($endpoint, "calls {$endpoint}"), arguments: $response->arguments);
            }
        }

        return $gaps;
    }

    /**
     * @param  list<stdClass>  $records
     * @param  array<string, BroadcastEvent>  $events
     * @return list<Gap>
     */
    private function broadcasts(array $records, array $events, ShapeCoverage $shapes): array
    {
        $payloads = [];
        $patterns = [];

        foreach ($records as $record) {
            if ($record->kind === 'broadcast' && is_string($record->event ?? null)) {
                $payloads[$record->event][] = $record->payload ?? null;

                foreach (is_array($record->patterns ?? null) ? $record->patterns : [] as $pattern) {
                    $patterns[$record->event][(string) $pattern] = true;
                }
            }
        }

        $gaps = [];

        foreach ($events as $key => $event) {
            if (! isset($payloads[$key])) {
                $gaps[] = new Gap(
                    "{$key}: no feature test broadcasts this event",
                    [],
                    "add a feature test that broadcasts {$event->class}, so its {$event->payload} payload is checked",
                    unexercised: true,
                );

                continue;
            }

            // Problem P-144: a declared channel no test broadcasts the event on may be one it is no longer sent on,
            // whose listeners would never hear it. A broadcast on a channel the event doesn't declare already fails its
            // Contract, which `tsc` reports after this step, so the gap would only hide that diagnostic.
            $stray = array_diff(array_keys($patterns[$key] ?? []), $event->channels) !== [];

            foreach ($stray ? [] : $event->channels as $pattern) {
                if (! isset($patterns[$key][$pattern])) {
                    $gaps[] = new Gap(
                        "{$key} on {$pattern}: no feature test broadcasts this event on this channel",
                        [],
                        "add a feature test that broadcasts {$event->class} on it, or remove #[BroadcastsOn('{$pattern}')] if it is never sent there",
                        unexercised: true,
                    );
                }
            }

            $shapes->observe($event->payload, $payloads[$key], new Origin("broadcast {$key}", "broadcasts {$key}"));
        }

        return $gaps;
    }

    /**
     * Problem P-144: a public channel config/strictly.php declares exists only for listeners to name, so one no event
     * declares #[BroadcastsOn] for is a channel they would hear nothing on.
     *
     * @param  array<string, BroadcastEvent>  $events
     * @param  list<string>  $publicChannels
     * @return list<Gap>
     */
    private function publicChannels(array $events, array $publicChannels): array
    {
        $declared = array_merge([], ...array_values(array_map(fn (BroadcastEvent $event): array => $event->channels, $events)));

        return array_values(array_map(
            fn (string $pattern): Gap => new Gap(
                "{$pattern}: no event's #[BroadcastsOn] names this public channel",
                [],
                'declare it on the events sent on it, or remove it from public_channels',
                unexercised: true,
            ),
            array_diff($publicChannels, $declared),
        ));
    }
}
