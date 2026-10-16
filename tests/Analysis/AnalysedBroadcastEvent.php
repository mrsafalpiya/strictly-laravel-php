<?php

namespace StrictlyLaravel\Tests\Analysis;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Spatie\LaravelData\Data;
use StrictlyLaravel\Broadcasting\BroadcastsData;
use StrictlyLaravel\Broadcasting\BroadcastsOn;

/**
 * A broadcast event as an app writes one, so `pnpm analyse:php` analyses BroadcastsData: PHPStan analyses a trait only
 * in a class that uses it, and nothing in the package does (Problem P-203).
 */
#[BroadcastsOn('analysis.{id}')]
final class AnalysedBroadcastEvent implements ShouldBroadcast
{
    use BroadcastsData;

    public function __construct(
        private readonly AnalysedPayloadData $payload,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("analysis.{$this->payload->id}");
    }

    public function broadcastData(): AnalysedPayloadData
    {
        return $this->payload;
    }
}

final class AnalysedPayloadData extends Data
{
    public function __construct(
        public int $id,
    ) {}
}
