<?php

namespace StrictlyLaravel\Broadcasting;

use Attribute;

/**
 * A channel a broadcast event is sent on, by its pattern (Problem P-144): one the app knows, a private or presence one
 * routes/channels.php defines or a public one config/strictly.php declares. `broadcastOn()` usually builds the
 * channel's name at runtime (`"issues.{$this->issue->id}"`), so the event names each pattern it sends to here, once per
 * pattern:
 *
 *     #[BroadcastsOn('issues.{issue}')]
 *     final class CommentPosted implements ShouldBroadcast
 *
 * `strictly:generate` maps each pattern to the events declared on it (`ChannelEvents` in `@/generated/broadcasting`),
 * so `useChannelEvent()` takes an event only on a channel it is sent on; a pattern the app doesn't know fails
 * generation. Each broadcast a feature test makes is checked against the declaration (its Contract fails on a channel
 * it doesn't declare), and Contract Coverage reports a declared pattern no test broadcasts the event on. The PHPStan
 * rule `strictly.broadcastsOn` requires one on every broadcast event.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class BroadcastsOn
{
    /**
     * @param  string  $pattern  as routes/channels.php defines it or config/strictly.php declares it: `issues.{issue}`
     */
    public function __construct(
        public string $pattern,
    ) {}
}
