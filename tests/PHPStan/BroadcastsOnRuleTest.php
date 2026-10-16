<?php

namespace StrictlyLaravel\Tests\PHPStan;

use PHPStan\Rules\Rule;
use StrictlyLaravel\PHPStan\BroadcastsOnRule;

/**
 * @extends StrictlyRuleTestCase<BroadcastsOnRule>
 */
final class BroadcastsOnRuleTest extends StrictlyRuleTestCase
{
    protected function getRule(): Rule
    {
        return new BroadcastsOnRule;
    }

    public function test_a_broadcast_event_declares_the_channels_it_is_sent_on(): void
    {
        $tip = 'Add #[BroadcastsOn(\'issues.{issue}\')] (StrictlyLaravel\Broadcasting\BroadcastsOn) for each channel pattern broadcastOn() sends it to: useChannelEvent() takes the event only on the channels it declares.';

        $this->analyse([__DIR__.'/data/broadcasts-on.php'], [
            ['Broadcast event App\Events\CommentDeleted declares no channel it is sent on.', 55, $tip],
            ['Broadcast event App\Events\CommentHidden declares no channel it is sent on.', 71, $tip],
        ]);
    }
}
