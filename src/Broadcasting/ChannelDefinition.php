<?php

namespace StrictlyLaravel\Broadcasting;

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
     */
    public function __construct(
        public string $pattern,
        public string $visibility,
        public array $parameters,
    ) {}

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
