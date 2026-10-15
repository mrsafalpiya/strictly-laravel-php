<?php

namespace StrictlyLaravel\Broadcasting;

use Spatie\LaravelData\Data;
use StrictlyLaravel\Routes\RouteKey;

/**
 * A channel the app knows (BroadcastChannels): a private or presence one routes/channels.php defines, or a public one
 * config/strictly.php declares (PublicChannels).
 */
final readonly class ChannelDefinition
{
    /**
     * @param  string  $pattern  as defined: `issues.{issue}`
     * @param  'public'|'private'|'presence'  $visibility  how a client subscribes to it
     * @param  array<string, RouteKey|null>  $parameters  each parameter of the pattern, with the route key it is bound by
     *                                                    (null for a plain value)
     * @param  class-string<Data>|null  $member  a presence channel's member data: the Data class its callback returns
     *                                           (null when it returns an array, which nothing types, Problem P-145)
     */
    public function __construct(
        public string $pattern,
        public string $visibility,
        public array $parameters,
        public ?string $member = null,
    ) {}

    /**
     * Whether the server broadcasting on a channel of this name sends to this channel: its prefix is this channel's
     * visibility (`private-`, `private-encrypted-`, `presence-`, none for a public one), and the rest matches the
     * pattern as Laravel's broadcaster matches it when it authorizes a subscription, each parameter one segment.
     */
    public function matches(string $name): bool
    {
        $prefixes = match ($this->visibility) {
            'public' => [''],
            'presence' => ['presence-'],
            default => ['private-encrypted-', 'private-'],
        };
        $regex = '/^'.preg_replace('/\\\\\{.*?\\\\\}/', '[^.]+', preg_quote($this->pattern, '/')).'$/';

        foreach ($prefixes as $prefix) {
            if ($prefix === '' && preg_match('/^(private|presence)-/', $name) === 1) {
                continue;
            }

            if (str_starts_with($name, $prefix) && preg_match($regex, substr($name, strlen($prefix))) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The names the server broadcasts on for it, as a TypeScript template literal type: `` `private-issues.${string}` ``
     * (and the encrypted variant of a private channel), `` `projects.${string}.public` `` for a public one.
     */
    public function broadcastNames(): string
    {
        $name = str_replace('`', '\\`', (string) preg_replace('/\{.*?\}/', '${string}', $this->pattern));
        $prefixes = match ($this->visibility) {
            'public' => [''],
            'presence' => ['presence-'],
            default => ['private-', 'private-encrypted-'],
        };

        return implode(' | ', array_map(fn (string $prefix): string => "`{$prefix}{$name}`", $prefixes));
    }
}
