<?php

namespace StrictlyLaravel\Broadcasting;

use Spatie\LaravelData\Data;

/**
 * A broadcast event whose payload is a Data class (Problem P-052). The event returns the payload from
 * `broadcastData()`, typed with its Data class, and this trait sends it as the payload Laravel broadcasts:
 *
 *     final class CommentPosted implements ShouldBroadcast
 *     {
 *         use BroadcastsData, Dispatchable, InteractsWithSockets;
 *
 *         public function __construct(private readonly Comment $comment) {}
 *
 *         public function broadcastOn(): PrivateChannel
 *         {
 *             return new PrivateChannel("issues.{$this->comment->issue_id}");
 *         }
 *
 *         public function broadcastData(): CommentData
 *         {
 *             return CommentData::fromModel($this->comment);
 *         }
 *     }
 *
 * `strictly:generate` writes the event into `@/generated/broadcasting` (BroadcastingModule): its name, as Echo listens
 * for it, mapped to the generated type of that Data class, so `useChannelEvent(channel, 'CommentPosted', cb)` types
 * `cb`'s payload. Every broadcast a feature test makes is recorded as a Contract checked against it
 * (ContractRecorder), and Contract Coverage reports an event no test broadcasts. The PHPStan rule
 * `strictly.broadcastData` rejects a broadcast event that sends its payload any other way.
 */
trait BroadcastsData
{
    /** The payload the event broadcasts. Declare its Data class as the return type: it types the listeners. */
    abstract public function broadcastData(): Data;

    /**
     * The payload as Laravel broadcasts it: the Data object transformed as laravel-data sends it.
     *
     * @return array<array-key, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->broadcastData()->toArray();
    }
}
