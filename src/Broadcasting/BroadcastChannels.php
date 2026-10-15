<?php

namespace StrictlyLaravel\Broadcasting;

use Closure;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Reflector;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;
use Spatie\LaravelData\Data;
use StrictlyLaravel\Routes\RouteKey;
use Throwable;

/**
 * The channels the app defines in routes/channels.php (`Broadcast::channel('issues.{issue}', …)`), as the broadcaster
 * registered them (Problem P-054). Each pattern's parameters are bound as Laravel binds them when it authorizes a
 * subscription: a parameter of the callback (or of a channel class's `join()`) with the same name, type-hinted with a
 * model, takes that model's route key (`fn (User $user, Issue $issue): bool` binds `{issue}` by the issue's `id`); any
 * other parameter is a plain value.
 *
 * A callback that returns user data authorizes a presence channel, any other a private one, so the client subscribes
 * with the visibility the server answers for. The user data is what each member shares with the others: a Data class
 * the callback declares as its return type (`fn (User $user, Issue $issue): ?ViewerData => …`) types the channel's
 * members (Problem P-145); an `array` return type still makes it a presence channel, whose members nothing types, so
 * the PHPStan rule `strictly.presenceData` rejects it.
 *
 * Public channels need no definition, so they come from config/strictly.php instead (PublicChannels, Problem P-143).
 */
final class BroadcastChannels
{
    /**
     * Every channel the app knows: the private and presence ones routes/channels.php defines, and the public ones
     * config/strictly.php declares, by pattern. A public declaration of a pattern routes/channels.php defines too is
     * left out (PublicChannels::check() fails `strictly:generate` on it).
     *
     * @return array<string, ChannelDefinition>
     */
    public static function of(Application $app): array
    {
        $channels = self::defined($app) + PublicChannels::declared();

        ksort($channels);

        return $channels;
    }

    /**
     * Every channel the app's broadcaster has registered from routes/channels.php, by pattern; none when broadcasting
     * isn't set up.
     *
     * @return array<string, ChannelDefinition>
     */
    public static function defined(Application $app): array
    {
        if (! $app->resolved(BroadcastManager::class)) {
            // Nothing has registered a channel: routes/channels.php resolves the manager to register them.
            return [];
        }

        try {
            $channels = $app->make(BroadcastManager::class)->driver()->getChannels()->all();
        } catch (Throwable) {
            // A broadcaster that can't be created has no channels registered either.
            return [];
        }

        $definitions = [];

        foreach ($channels as $pattern => $callback) {
            $pattern = (string) $pattern;
            $function = self::reflect($callback);
            $parameters = [];

            preg_match_all('/\{(.*?)\}/', $pattern, $names);

            foreach ($names[1] as $name) {
                $parameters[$name] = $function === null ? null : self::routeKey($function, $name);
            }

            $member = $function === null ? false : self::member($function);

            $definitions[$pattern] = new ChannelDefinition(
                $pattern,
                $member !== false ? 'presence' : 'private',
                $parameters,
                $member === false ? null : $member,
            );
        }

        ksort($definitions);

        return $definitions;
    }

    /**
     * The binding fields the channels use for each model, as RouteKeys reads them from the routes: a Data class built
     * from the model has the property named after one generated as the model's route key.
     *
     * @return array<class-string<Model>, list<string>>
     */
    public static function bindings(Application $app): array
    {
        $fields = [];

        foreach (self::of($app) as $definition) {
            foreach ($definition->parameters as $key) {
                if ($key !== null) {
                    $fields[$key->model][] = $key->field;
                }
            }
        }

        return array_map(fn (array $names): array => array_values(array_unique($names)), $fields);
    }

    private static function reflect(mixed $callback): ?ReflectionFunctionAbstract
    {
        try {
            return match (true) {
                $callback instanceof Closure => new ReflectionFunction($callback),
                is_string($callback) && method_exists($callback, 'join') => new ReflectionMethod($callback, 'join'),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The route key a channel parameter is bound by, matched by name as Laravel's broadcaster matches it.
     */
    private static function routeKey(ReflectionFunctionAbstract $function, string $name): ?RouteKey
    {
        foreach ($function->getParameters() as $parameter) {
            $class = $parameter->getName() === $name ? Reflector::getParameterClassName($parameter) : null;

            if ($class !== null && is_subclass_of($class, Model::class)) {
                return new RouteKey($class, (new $class)->getRouteKeyName());
            }
        }

        return null;
    }

    /**
     * What a presence channel's callback returns for each member: the Data class among its declared return types, null
     * for an `array` (untyped member data), or false when it returns no user data at all (a private channel).
     *
     * @return class-string<Data>|false|null
     */
    private static function member(ReflectionFunctionAbstract $function): string|false|null
    {
        $type = $function->getReturnType();
        $types = match (true) {
            $type instanceof ReflectionNamedType => [$type],
            $type instanceof ReflectionUnionType => $type->getTypes(),
            default => [],
        };
        $member = false;

        foreach ($types as $returned) {
            if (! $returned instanceof ReflectionNamedType) {
                continue;
            }

            if (! $returned->isBuiltin() && is_subclass_of($returned->getName(), Data::class)) {
                return $returned->getName();
            }

            if ($returned->getName() === 'array') {
                $member = null;
            }
        }

        return $member;
    }
}
