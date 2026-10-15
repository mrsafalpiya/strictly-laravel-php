<?php

namespace StrictlyLaravel\Tests\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use StrictlyLaravel\PHPStan\BroadcastDataRule;

/**
 * @extends RuleTestCase<BroadcastDataRule>
 */
final class BroadcastDataRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new BroadcastDataRule($this->createReflectionProvider());
    }

    public function test_a_broadcast_event_sends_its_payload_as_the_data_class_broadcast_data_declares(): void
    {
        $untyped = 'Use StrictlyLaravel\Broadcasting\BroadcastsData and return the payload from broadcastData(), declaring its Data class: `public function broadcastData(): CommentData`.';
        $replaced = 'Remove it: BroadcastsData broadcasts what broadcastData() returns, which its Data class types.';
        $undeclared = 'Return a Data class that isn\'t a Page class, such as `public function broadcastData(): CommentData`: its generated type types the listeners.';

        $this->analyse([__DIR__.'/data/broadcast-events.php'], [
            ['Broadcast event App\Events\CommentEdited sends a payload that has no generated type.', 45, $untyped],
            ['Broadcast event App\Events\CommentDeleted sends a payload that has no generated type.', 56, $untyped],
            ['App\Events\IssueClosed::broadcastWith() replaces the payload broadcastData() declares.', 71, $replaced],
            ['App\Events\IssueReopened::broadcastData() must declare the one Data class its payload is as its return type.', 93, $undeclared],
            ['App\Events\CommentsRefreshed::broadcastData() must declare the one Data class its payload is as its return type.', 109, $undeclared],
        ]);
    }
}
