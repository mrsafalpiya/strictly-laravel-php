<?php

namespace StrictlyLaravel\Broadcasting;

use Illuminate\Filesystem\Filesystem;
use StrictlyLaravel\Routes\RouteKey;
use StrictlyLaravel\Routes\WayfinderTemplates;

/**
 * Writes `broadcasting.ts` into the generated directory (`@/generated/broadcasting`): the app's broadcast events and
 * channels, which events are sent on which channel, the data each member of a presence channel shares, and the npm
 * package's Echo helpers bound to them (Problems P-052, P-053, P-054, P-143, P-144, P-145):
 *
 *     export type BroadcastEvents = { CommentPosted: import('@/generated').CommentData };
 *     export type BroadcastChannels = {
 *         'issues.{issue}': { visibility: 'private'; params: { issue: RouteKey<'Issue', number> | … } };
 *         'issues.{issue}.viewers': { visibility: 'presence'; params: { … }; member: import('@/generated').ViewerData };
 *     };
 *     export type ChannelEvents = { 'issues.{issue}': 'CommentPosted' };
 *     export type EventChannels = { CommentPosted: 'issues.{issue}' };
 *     export const { channel, publicChannel, useChannelEvent, usePresence } = createBroadcasting<BroadcastEvents, BroadcastChannels, ChannelEvents>({ … });
 *
 * so `useChannelEvent(channel('issues.{issue}', { issue: issue.id }), 'CommentPosted', (comment) => …)` takes only a
 * defined channel with its parameters, only an event its #[BroadcastsOn] declares on that channel (Problem P-144), and
 * types the payload from its Data class; `publicChannel('projects.{project}.public', { project })` takes only a public
 * channel config/strictly.php declares; `usePresence(channel('issues.{issue}.viewers', { issue }), { here, joining, leaving })`
 * types the members from the Data class the presence channel's callback returns.
 * `BroadcastChannelName` is every name the server may broadcast on a channel the app knows under, which the broadcast
 * Contracts check.
 */
final class BroadcastingModule
{
    public const string FILE = 'broadcasting.ts';

    /** The npm package's Echo helper subpath. */
    public const string ECHO_MODULE = '@mrsafalpiya/strictly-laravel/echo';

    /**
     * @param  array<string, BroadcastEvent>  $events  BroadcastEvents::in()
     * @param  array<string, ChannelDefinition>  $channels  BroadcastChannels::of(): the defined and the public ones
     */
    public static function write(array $events, array $channels, string $outputDirectory, Filesystem $files): void
    {
        $files->put("{$outputDirectory}/".self::FILE, self::module($events, $channels));
    }

    /**
     * @param  array<string, BroadcastEvent>  $events
     * @param  array<string, ChannelDefinition>  $channels
     */
    private static function module(array $events, array $channels): string
    {
        $eventLines = array_map(
            fn (BroadcastEvent $event): string => sprintf('    %s: %s;', self::literal($event->key), WayfinderTemplates::generatedType($event->payload)),
            array_values($events),
        );
        $eventChannelLines = array_map(
            fn (BroadcastEvent $event): string => sprintf(
                '    %s: %s;',
                self::literal($event->key),
                $event->channels === [] ? 'never' : implode(' | ', array_map(self::literal(...), $event->channels)),
            ),
            array_values($events),
        );
        $channelLines = [];
        $definitionLines = [];
        $channelEventLines = [];

        foreach ($channels as $definition) {
            $sent = array_values(array_map(
                fn (BroadcastEvent $event): string => self::literal($event->key),
                array_filter($events, fn (BroadcastEvent $event): bool => in_array($definition->pattern, $event->channels, true)),
            ));
            $channelEventLines[] = sprintf('    %s: %s;', self::literal($definition->pattern), $sent === [] ? 'never' : implode(' | ', $sent));

            $params = [];
            $keys = [];

            foreach ($definition->parameters as $name => $key) {
                $params[] = self::literal($name).': '.self::parameterType($key);
                $keys[] = self::literal($name).': '.($key === null ? 'null' : self::literal($key->field));
            }

            $channelLines[] = sprintf(
                "    %s: { visibility: '%s'; params: { %s }%s };",
                self::literal($definition->pattern),
                $definition->visibility,
                implode('; ', $params),
                $definition->visibility === 'presence' ? '; member: '.self::memberType($definition->member) : '',
            );
            $definitionLines[] = sprintf(
                "    %s: { visibility: '%s', keys: { %s } },",
                self::literal($definition->pattern),
                $definition->visibility,
                implode(', ', $keys),
            );
        }

        $names = array_map(fn (ChannelDefinition $definition): string => $definition->broadcastNames(), array_values($channels));
        $echo = self::ECHO_MODULE;

        return sprintf(
            <<<'TS'
                // Generated by `php artisan strictly:generate` from the app's broadcast events, routes/channels.php and the
                // public channels config/strictly.php declares. Do not edit.
                import { createBroadcasting } from '%s';

                /**
                 * Every event the app broadcasts with a Data class payload (BroadcastsData), by the name Echo listens for it
                 * (`CommentPosted` for App\Events\CommentPosted, `.comment.posted` for an event broadcast as `comment.posted`),
                 * with the type of its payload.
                 */
                export type BroadcastEvents = {%s};

                /**
                 * Every channel the app knows, by its pattern: the private and presence ones routes/channels.php defines and the
                 * public ones config/strictly.php declares. How a client subscribes to it, the value each parameter of its name
                 * takes (a model's route key where the channel binds the model), and for a presence channel, the data each
                 * member shares: the Data class its callback returns.
                 */
                export type BroadcastChannels = {%s};

                /** Every name the server can broadcast on a channel the app knows. */
                export type BroadcastChannelName = %s;

                /**
                 * The events sent on each channel the app knows, by its pattern: those whose #[BroadcastsOn] declare it.
                 */
                export type ChannelEvents = {%s};

                /** The patterns of the channels each event is sent on, as its #[BroadcastsOn] declare them. */
                export type EventChannels = {%s};

                /**
                 * `channel(pattern, params)` names a channel routes/channels.php defines, `publicChannel(pattern, params)` a public
                 * one config/strictly.php declares, `useChannelEvent(channel, event, callback)` listens on it for an event sent
                 * on it, typing the payload, and `usePresence(channel, { here, joining, leaving })` follows a presence channel's
                 * members, typed as its callback's Data class.
                 */
                export const { channel, publicChannel, useChannelEvent, usePresence } = createBroadcasting<BroadcastEvents, BroadcastChannels, ChannelEvents>({%s});

                TS,
            $echo,
            self::block($eventLines),
            self::block($channelLines),
            $names === [] ? 'never' : implode(' | ', $names),
            self::block($channelEventLines),
            self::block($eventChannelLines),
            self::block($definitionLines),
        );
    }

    /**
     * What a channel parameter takes: a model's route key, or the model's Data object holding it (`{ id: … }`), as a
     * route function takes it; a plain parameter takes any value its name can hold.
     */
    private static function parameterType(?RouteKey $key): string
    {
        if ($key === null) {
            return 'string | number';
        }

        $model = new $key->model;
        $value = match (true) {
            $key->field !== $model->getKeyName() => 'string | number',
            in_array($model->getKeyType(), ['int', 'integer'], true) => 'number',
            default => 'string',
        };
        $type = sprintf("import('%s').RouteKey<'%s', %s>", WayfinderTemplates::TYPES_MODULE, $key->brand(), $value);

        return sprintf('%s | { %s: %s }', $type, self::literal($key->field), $type);
    }

    /**
     * What each member of a presence channel shares: the Data class its callback returns, or `unknown` for one that
     * returns an array, which nothing types (Problem P-145).
     *
     * @param  class-string|null  $member
     */
    private static function memberType(?string $member): string
    {
        return $member === null ? 'unknown' : WayfinderTemplates::generatedType($member);
    }

    /**
     * @param  list<string>  $lines
     */
    private static function block(array $lines): string
    {
        return $lines === [] ? '' : "\n".implode("\n", $lines)."\n";
    }

    /** A TypeScript string literal. */
    private static function literal(string $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
