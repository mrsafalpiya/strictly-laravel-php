<?php

namespace StrictlyLaravel\Broadcasting;

use Illuminate\Database\Eloquent\Model;
use StrictlyLaravel\Routes\RouteKey;

/**
 * The public broadcast channels config/strictly.php declares (Problem P-143). Laravel defines a public channel nowhere:
 * `new Channel('status')` needs no entry in routes/channels.php, so without a declaration its name would be free text
 * on both sides. Each entry is a name or a pattern, and a pattern keyed by itself names the model each of its
 * parameters holds the route key of, as a channel callback's type-hint does for routes/channels.php:
 *
 *     'public_channels' => [
 *         'status',
 *         'projects.{project}.public' => ['project' => Project::class],
 *     ],
 *
 * BroadcastChannels::of() returns them next to the channels routes/channels.php defines, with the visibility `public`.
 * A declaration that names nothing fails `strictly:generate` (check()).
 */
final class PublicChannels
{
    public const string KEY = 'public_channels';

    /**
     * Every valid declaration, by pattern; check() reports the others.
     *
     * @return array<string, ChannelDefinition>
     */
    public static function declared(): array
    {
        $definitions = [];

        foreach (self::entries() as [$pattern, $models]) {
            if (! is_string($pattern) || self::errors($pattern, $models) !== []) {
                continue;
            }

            $parameters = [];

            foreach (self::parameters($pattern) as $name) {
                $model = is_array($models) ? ($models[$name] ?? null) : null;
                $parameters[$name] = is_string($model) && is_subclass_of($model, Model::class)
                    ? new RouteKey($model, (new $model)->getRouteKeyName())
                    : null;
            }

            $definitions[$pattern] = new ChannelDefinition($pattern, 'public', $parameters);
        }

        return $definitions;
    }

    /**
     * What is wrong with the declarations, one line each: an entry that isn't a name, a model that doesn't exist or
     * names no parameter of its pattern, a name Echo would subscribe to with authorization, or a pattern
     * routes/channels.php defines too. `strictly:generate` fails on any, since `publicChannel()` would otherwise take a
     * name nothing declares.
     *
     * @param  array<string, ChannelDefinition>  $defined  BroadcastChannels::defined()
     * @return list<string>
     */
    public static function check(array $defined): array
    {
        $declared = config('strictly.'.self::KEY, []);

        if (! is_array($declared)) {
            return ['strictly.'.self::KEY.' must be a list of channel names, each keyed by itself with its parameters\' models if it has any.'];
        }

        $errors = [];

        foreach (self::entries() as [$pattern, $models]) {
            array_push($errors, ...self::errors($pattern, $models));

            if (is_string($pattern) && isset($defined[$pattern])) {
                $errors[] = 'strictly.'.self::KEY.".{$pattern}: routes/channels.php defines a {$defined[$pattern]->visibility} channel with this pattern too. Name the public channel differently, so that `channel()` and `publicChannel()` never mean the same pattern.";
            }
        }

        return $errors;
    }

    /**
     * Each declaration as [pattern, models]: a list entry has no models.
     *
     * @return list<array{mixed, mixed}>
     */
    private static function entries(): array
    {
        $declared = config('strictly.'.self::KEY, []);
        $entries = [];

        foreach (is_array($declared) ? $declared : [] as $key => $value) {
            $entries[] = is_int($key) ? [$value, null] : [$key, $value];
        }

        return $entries;
    }

    /**
     * @return list<string>
     */
    private static function errors(mixed $pattern, mixed $models): array
    {
        $where = 'strictly.'.self::KEY;

        if (! is_string($pattern) || trim($pattern) === '') {
            return ["{$where}: ".var_export($pattern, true).' is not a channel name. List each public channel by its name, such as \'status\' or \'projects.{project}.public\'.'];
        }

        if (preg_match('/^(private|presence)-/', $pattern) === 1) {
            return ["{$where}.{$pattern}: Echo subscribes to a channel named `private-…` or `presence-…` with authorization, so it isn't public. Define it in routes/channels.php instead, and name it with `channel()`."];
        }

        if ($models === null) {
            return [];
        }

        if (! is_array($models)) {
            return ["{$where}.{$pattern}: must map the pattern's parameters to the models whose route keys they hold, such as ['project' => Project::class]."];
        }

        $errors = [];
        $parameters = self::parameters($pattern);

        foreach ($models as $name => $model) {
            if (! in_array($name, $parameters, true)) {
                $errors[] = "{$where}.{$pattern}.{$name}: the pattern has no parameter {".$name.'}.';
            } elseif (! is_string($model) || ! is_subclass_of($model, Model::class)) {
                $errors[] = "{$where}.{$pattern}.{$name}: ".(is_string($model) ? "`{$model}`" : var_export($model, true)).' is not an Eloquent model class.';
            }
        }

        return $errors;
    }

    /**
     * The parameter names of a pattern: `project` for `projects.{project}.public`.
     *
     * @return list<string>
     */
    private static function parameters(string $pattern): array
    {
        preg_match_all('/\{(.*?)\}/', $pattern, $names);

        return $names[1];
    }
}
