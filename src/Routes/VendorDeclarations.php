<?php

namespace StrictlyLaravel\Routes;

use Illuminate\Routing\Router;
use ReflectionClass;
use Spatie\LaravelData\Data;
use StrictlyLaravel\Data\InputData;
use StrictlyLaravel\Data\QueryData;
use StrictlyLaravel\Inertia\Page;
use Throwable;

/**
 * What config/strictly.php declares for the actions of controllers the app doesn't own (Problems P-025, P-050), such as
 * Fortify's. Their actions take no input class and declare no Data return type, so their payload, query string and
 * JSON response are named by hand, keyed by `Controller@method` (`@__invoke` for an invokable controller):
 *
 *     'vendor_inputs' => [AuthenticatedSessionController::class.'@store' => LoginData::class],          // an InputData
 *     'vendor_queries' => [NewPasswordController::class.'@create' => ResetPasswordQueryData::class],    // a QueryData
 *     'vendor_responses' => [
 *         TwoFactorSecretKeyController::class.'@show' => TwoFactorSecretKeyData::class,                 // a Data class
 *         TwoFactorQrCodeController::class.'@show' => '{ svg: string; url: string } | []',              // or a TS type
 *     ],
 *
 * RouteInput, RouteQuery and RouteResponse read them before the action's own types, so a declared action is branded,
 * recorded and covered exactly like one of the app's. The declarations are verified, not trusted: the feature tests
 * check every key the vendor validates, reads or reports an error for against the declared input, and every response
 * against the declared type (ContractRecorder), and Contract Coverage reports a declared field the vendor never uses
 * (ContractCoverage). A declaration whose action or class doesn't exist fails `strictly:generate` (check()).
 */
final class VendorDeclarations
{
    public const string INPUTS = 'vendor_inputs';

    public const string QUERIES = 'vendor_queries';

    public const string RESPONSES = 'vendor_responses';

    /**
     * The declared input of an action, if any.
     *
     * @return class-string<InputData>|null
     */
    public static function input(string $controller, string $method): ?string
    {
        $declared = self::declared(self::INPUTS, $controller, $method);

        return $declared !== null && is_subclass_of($declared, InputData::class) ? $declared : null;
    }

    /**
     * The declared query class of an action, if any.
     *
     * @return class-string<QueryData>|null
     */
    public static function query(string $controller, string $method): ?string
    {
        $declared = self::declared(self::QUERIES, $controller, $method);

        return $declared !== null && is_subclass_of($declared, QueryData::class) ? $declared : null;
    }

    /**
     * The declared response of an action, if any: a Data class, or a TypeScript type for JSON that isn't one object.
     *
     * @return class-string<Data>|string|null
     */
    public static function response(string $controller, string $method): ?string
    {
        $declared = self::declared(self::RESPONSES, $controller, $method);

        if ($declared === null || trim($declared) === '') {
            return null;
        }

        if (self::isTypeScript($declared)) {
            return $declared;
        }

        return class_exists($declared) && is_subclass_of($declared, Data::class) && ! is_subclass_of($declared, Page::class) ? $declared : null;
    }

    /** Whether a declared response is a TypeScript type rather than a class name. */
    public static function isTypeScript(string $response): bool
    {
        // A class name has a namespace: checked first, so a TypeScript type never loads a class.
        return ! str_contains($response, '\\') && ! class_exists($response);
    }

    /**
     * Every declared input and query class, with the endpoints of the routes that run its action (`POST /login`, named
     * as FormEndpoints does): what Contract Coverage checks the declared fields of.
     *
     * @return list<array{action: string, kind: 'input'|'query', class: class-string<InputData|QueryData>, endpoints: list<string>}>
     */
    public static function payloads(Router $router): array
    {
        $endpoints = self::endpointsByAction($router);
        $payloads = [];

        foreach ([self::INPUTS => [InputData::class, 'input'], self::QUERIES => [QueryData::class, 'query']] as $key => [$base, $kind]) {
            foreach (self::entries($key) as $action => $class) {
                if (class_exists($class) && is_subclass_of($class, $base)) {
                    $payloads[] = ['action' => $action, 'kind' => $kind, 'class' => $class, 'endpoints' => $endpoints[$action] ?? []];
                }
            }
        }

        return $payloads;
    }

    /**
     * What is wrong with the declarations, one line each: an action that doesn't exist or that no route runs, a class
     * that doesn't exist or doesn't extend what its key needs. `strictly:generate` fails on any, since a declaration
     * that names nothing would leave its route silently untyped.
     *
     * @return list<string>
     */
    public static function check(Router $router): array
    {
        $errors = [];
        $routed = self::endpointsByAction($router);
        $expected = [
            self::INPUTS => [InputData::class, 'an input class (a subclass of '.InputData::class.')'],
            self::QUERIES => [QueryData::class, 'a query class (a subclass of '.QueryData::class.')'],
            self::RESPONSES => [Data::class, 'a Data class that isn\'t a Page class, or a TypeScript type'],
        ];

        foreach ($expected as $key => [$base, $description]) {
            $entries = config("strictly.{$key}", []);

            if (! is_array($entries)) {
                $errors[] = "strictly.{$key} must be an array of `Controller@method` => class.";

                continue;
            }

            foreach ($entries as $action => $declared) {
                $where = "strictly.{$key}.{$action}";

                if (! is_string($action) || ! self::actionExists($action)) {
                    $errors[] = "{$where}: no controller action `".(is_string($action) ? $action : var_export($action, true)).'` exists. Key each declaration by `Controller::class.\'@method\'` (`@__invoke` for an invokable controller).';

                    continue;
                }

                if (! isset($routed[self::normalise($action)])) {
                    $errors[] = "{$where}: no route runs this action. Remove the declaration, or register the vendor's route (a Fortify feature that is turned off has none).";

                    continue;
                }

                if (! is_string($declared) || trim($declared) === '') {
                    $errors[] = "{$where}: declare {$description}.";

                    continue;
                }

                if ($key === self::RESPONSES && self::isTypeScript($declared)) {
                    continue; // a TypeScript type
                }

                $missing = self::missing($declared);

                if ($missing !== null) {
                    $errors[] = "{$where}: {$missing}";
                } elseif (! is_subclass_of($declared, $base) || is_subclass_of($declared, Page::class)) {
                    $errors[] = "{$where}: {$declared} isn't {$description}.";
                }
            }
        }

        return $errors;
    }

    /**
     * @return array<string, string>
     */
    private static function entries(string $key): array
    {
        $entries = config("strictly.{$key}", []);
        $valid = [];

        foreach (is_array($entries) ? $entries : [] as $action => $declared) {
            if (is_string($action) && is_string($declared)) {
                $valid[self::normalise($action)] = $declared;
            }
        }

        return $valid;
    }

    private static function declared(string $key, string $controller, string $method): ?string
    {
        return self::entries($key)[ltrim($controller, '\\').'@'.$method] ?? null;
    }

    /**
     * The endpoints of the routes that run each controller action, by `Controller@method`.
     *
     * @return array<string, list<string>>
     */
    private static function endpointsByAction(Router $router): array
    {
        $endpoints = [];

        foreach ($router->getRoutes()->getRoutes() as $route) {
            $controller = is_string($route->getAction('uses')) ? $route->getControllerClass() : null;

            if ($controller === null) {
                continue;
            }

            // An invokable controller's action name has no `@method`.
            $method = str_contains($route->getActionName(), '@') ? $route->getActionMethod() : '__invoke';
            $endpoints[self::normalise($controller).'@'.$method][] = FormEndpoints::name($route);
        }

        return $endpoints;
    }

    private static function normalise(string $action): string
    {
        return ltrim($action, '\\');
    }

    private static function actionExists(string $action): bool
    {
        [$class, $method] = array_pad(explode('@', self::normalise($action), 2), 2, '');

        return $method !== '' && self::missing($class) === null && method_exists($class, $method);
    }

    /**
     * Why a declared class can't be used, or null when it loads under exactly the declared name. PHP's class names
     * ignore case but autoloading doesn't, so class_exists() alone would accept `LogInData` only while `LoginData`
     * happens to be loaded already; and a class saved mid-edit throws while it loads.
     */
    private static function missing(string $class): ?string
    {
        $class = self::normalise($class);

        try {
            $exists = class_exists($class);
        } catch (Throwable $exception) {
            return "the class {$class} can't be loaded: {$exception->getMessage()}";
        }

        if (! $exists) {
            return "the class {$class} doesn't exist.";
        }

        $name = (new ReflectionClass($class))->getName();

        return $name === $class ? null : "the class {$class} doesn't exist. Did you mean {$name}?";
    }
}
