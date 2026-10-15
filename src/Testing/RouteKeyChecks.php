<?php

namespace StrictlyLaravel\Testing;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Assert;
use Spatie\LaravelData\Resolvers\TransformedDataResolver;
use Spatie\LaravelData\Support\DataContainer;
use StrictlyLaravel\Routes\RouteKey;
use StrictlyLaravel\Routes\RouteKeys;

/**
 * Problem P-116: a model-key brand (`Key<'Project', 'id', number>`, StrictlyLaravel\Routes\RouteKeys) only proves
 * which model a key belongs to if the value really is one. So while a feature test's request is handled, every Data
 * property typed as a model's key (a route key included) is checked as laravel-data serialises it: the value must be
 * an existing key of that model by that field. A `#[KeyOf(Project::class)]` filled with an issue's id, or a
 * `fromModel(Project)` class whose `id` holds the user's id, fails the request with the value, the property and the
 * model, instead of the brand making the wrong value look right.
 *
 * Every table gets its own id range in tests (useDisjointIds()), so an id of the wrong model never exists by
 * coincidence: with a fresh database a user's id and a project's id would both be 1.
 */
final class RouteKeyChecks
{
    /** The ids of the n-th table start after n million. */
    private const int ID_RANGE = 1_000_000;

    private static ?RouteKeys $keys = null;

    /** @var array<string, array<string, RouteKey>> The model-key properties of each Data class, in this request. */
    private static array $properties = [];

    /** @var array<string, bool> Whether each key exists, in this request. */
    private static array $exists = [];

    /** @var array<string, string> The brands this request's Data objects violated. */
    private static array $failures = [];

    /** Start checking the requests handled from now on in this application. */
    public static function listen(Application $app): void
    {
        self::stop();

        Event::listen(RouteMatched::class, function () use ($app): void {
            self::stop();
            self::$keys = RouteKeys::of($app->make(Router::class));
        });

        Event::listen(RequestHandled::class, function (RequestHandled $event): void {
            $failures = self::$failures;
            self::stop();

            if ($failures !== []) {
                Assert::fail(sprintf(
                    "%s %s sent a value typed as a model's key that is no key of that model (Problem P-116):\n  %s",
                    $event->request->method(),
                    $event->request->getRequestUri(),
                    implode("\n  ", $failures),
                ));
            }
        });

        $app->bind(TransformedDataResolver::class, CheckedTransformedDataResolver::class);
        // laravel-data keeps the resolver it resolved first.
        DataContainer::get()->reset();
    }

    /**
     * Give every table of the test database its own id range, so ids of different models never coincide. SQLite only:
     * it keeps each table's last id in `sqlite_sequence`, which a test can move (Problem P-008).
     */
    public static function useDisjointIds(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite' || DB::select("select name from sqlite_master where name = 'sqlite_sequence'") === []) {
            return;
        }

        $tables = array_values(array_filter(
            Schema::getTableListing(schemaQualified: false),
            fn (string $table): bool => ! str_starts_with($table, 'sqlite_'),
        ));

        foreach ($tables as $index => $table) {
            DB::table('sqlite_sequence')->updateOrInsert(['name' => $table], ['seq' => ($index + 1) * self::ID_RANGE]);
        }
    }

    /** Called by CheckedTransformedDataResolver for every property laravel-data sends. */
    public static function check(string $dataClass, string $property, mixed $value): void
    {
        if (self::$keys === null) {
            return; // not inside a request
        }

        $key = (self::$properties[$dataClass] ??= self::$keys->inDataClass($dataClass))[$property] ?? null;

        if ($key === null) {
            return;
        }

        foreach (is_array($value) ? $value : [$value] as $item) {
            if ((is_int($item) || is_string($item)) && ! self::exists($key, $item)) {
                self::$failures["{$dataClass}.{$property}={$item}"] = sprintf(
                    '%s.%s is not the %s of any %s: %s',
                    $dataClass,
                    $property,
                    $key->field,
                    $key->model,
                    json_encode($item, JSON_THROW_ON_ERROR),
                );
            }
        }
    }

    private static function exists(RouteKey $key, int|string $value): bool
    {
        /** @var class-string<Model> $model */
        $model = $key->model;

        return self::$exists["{$model}|{$key->field}|{$value}"] ??= $model::query()->withoutGlobalScopes()->where($key->field, $value)->exists();
    }

    private static function stop(): void
    {
        self::$keys = null;
        self::$properties = [];
        self::$exists = [];
        self::$failures = [];
    }
}
