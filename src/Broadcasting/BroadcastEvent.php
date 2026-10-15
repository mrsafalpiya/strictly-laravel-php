<?php

namespace StrictlyLaravel\Broadcasting;

use Spatie\LaravelData\Data;

/**
 * A broadcast event whose payload is a Data class (BroadcastEvents).
 */
final readonly class BroadcastEvent
{
    /**
     * @param  class-string  $class
     * @param  string  $name  the name Laravel broadcasts it under (`App\Events\CommentPosted`, or its `broadcastAs()`)
     * @param  string  $key  the name Echo listens for it under, which `useChannelEvent()` takes (`CommentPosted`)
     * @param  class-string<Data>  $payload
     */
    public function __construct(
        public string $class,
        public string $name,
        public string $key,
        public string $payload,
    ) {}
}
