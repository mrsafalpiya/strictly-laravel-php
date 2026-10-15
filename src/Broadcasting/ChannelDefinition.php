<?php

namespace StrictlyLaravel\Broadcasting;

use StrictlyLaravel\Routes\RouteKey;

/**
 * A channel routes/channels.php defines (BroadcastChannels).
 */
final readonly class ChannelDefinition
{
    /**
     * @param  string  $pattern  as defined: `issues.{issue}`
     * @param  'private'|'presence'  $visibility  how a client subscribes to it
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
     * (and the encrypted variant of a private channel).
     */
    public function broadcastNames(): string
    {
        $name = str_replace('`', '\\`', (string) preg_replace('/\{.*?\}/', '${string}', $this->pattern));
        $prefixes = $this->visibility === 'presence' ? ['presence-'] : ['private-', 'private-encrypted-'];

        return implode(' | ', array_map(fn (string $prefix): string => "`{$prefix}{$name}`", $prefixes));
    }
}
