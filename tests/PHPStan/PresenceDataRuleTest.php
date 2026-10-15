<?php

namespace StrictlyLaravel\Tests\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use StrictlyLaravel\PHPStan\PresenceDataRule;

/**
 * @extends RuleTestCase<PresenceDataRule>
 */
final class PresenceDataRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new PresenceDataRule($this->createReflectionProvider());
    }

    public function test_a_presence_channel_returns_its_members_data_as_a_declared_data_class(): void
    {
        $array = 'Return a Data class instead, declared as the callback\'s return type (fn (User $user, Issue $issue): ?ViewerData => …): usePresence() types the members from it.';
        $either = 'Declare bool for a private channel (fn (User $user, Issue $issue): bool => …), or a Data class for a presence channel (fn (User $user, Issue $issue): ?ViewerData => …): strictly:generate reads the declared return type to tell them apart.';
        $undeclared = 'Declare it (fn (User $user, Issue $issue): ?ViewerData => …): strictly:generate reads the declared return type to type the members, and takes a channel without one for a private channel.';

        $this->analyse([__DIR__.'/data/presence-channels.php'], [
            ["Presence channel projects.{project}.viewers returns its members' data as an array.", 60, $array],
            ['Channel issues.{issue}.viewers returns an array or a bool, which doesn\'t tell a private channel from a presence one.', 61, $either],
            ["Presence channel issues.{issue}.watchers returns its members' data as an array.", 64, $array],
            ["Presence channel projects.{project}.watchers returns its members' data as an array.", 65, $array],
            ["Presence channel workspaces.{workspace}.viewers returns its members' data as an array.", 66, $array],
            ["A presence channel returns its members' data as an array.", 67, $array],
            ['Presence channel boards.{board}.viewers returns a Data class its callback doesn\'t declare as its return type.', 70, $undeclared],
            ['Channel projects.{project}.private returns an array or a bool, which doesn\'t tell a private channel from a presence one.', 73, $either],
            ['Channel boards.{board} returns an array or a bool, which doesn\'t tell a private channel from a presence one.', 74, $either],
        ]);
    }
}
