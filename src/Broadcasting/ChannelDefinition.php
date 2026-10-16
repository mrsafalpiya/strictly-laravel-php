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
        $regex = '/^'.preg_replace('/\\\\\{.*?\\\\\}/', '[^.]+', preg_quote($this->pattern, '/')).'$/';

        foreach ($this->prefixes() as $prefix) {
            if ($prefix === '' && self::isAuthorized($name)) {
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

        return implode(' | ', array_map(fn (string $prefix): string => "`{$prefix}{$name}`", $this->prefixes()));
    }

    /**
     * The parameter names of a channel pattern, in order: `project` for `projects.{project}.public`.
     *
     * @return list<string>
     */
    public static function parameterNames(string $pattern): array
    {
        preg_match_all('/\{(.*?)\}/', $pattern, $names);

        return $names[1];
    }

    /**
     * Whether Echo subscribes to a channel of this name with authorization: one named `private-…` (the encrypted
     * `private-encrypted-…` too) or `presence-…`, never a public one.
     */
    public static function isAuthorized(string $name): bool
    {
        return preg_match('/^(private|presence)-/', $name) === 1;
    }

    /**
     * The prefixes of the names the server broadcasts on for this channel's visibility: none for a public one.
     *
     * @return list<string>
     */
    private function prefixes(): array
    {
        return match ($this->visibility) {
            'public' => [''],
            'presence' => ['presence-'],
            default => ['private-', 'private-encrypted-'],
        };
    }
}
