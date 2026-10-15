<?php

namespace StrictlyLaravel\PHPStan;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use StrictlyLaravel\Broadcasting\BroadcastsOn;

/**
 * Problem P-144: a broadcast event declares the channels it is sent on, `#[BroadcastsOn('issues.{issue}')]` once per
 * pattern, since `broadcastOn()` builds their names at runtime. `strictly:generate` maps each pattern to the events
 * declared on it, so `useChannelEvent()` takes an event only on a channel it is sent on; an event that declares none
 * could be listened for on no channel at all. Each broadcast's Contract and Contract Coverage check the declaration
 * against `broadcastOn()`.
 *
 * @implements Rule<InClassNode>
 */
final readonly class BroadcastsOnRule implements Rule
{
    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getClassReflection();

        if (! $class->isClass() || $class->isAbstract() || ! $class->implementsInterface(ShouldBroadcast::class)) {
            return [];
        }

        if ($class->getNativeReflection()->getAttributes(BroadcastsOn::class) !== []) {
            return [];
        }

        return [RuleErrorBuilder::message("Broadcast event {$class->getName()} declares no channel it is sent on.")
            ->identifier('strictly.broadcastsOn')
            ->tip('Add #[BroadcastsOn(\'issues.{issue}\')] (StrictlyLaravel\Broadcasting\BroadcastsOn) for each channel pattern broadcastOn() sends it to: useChannelEvent() takes the event only on the channels it declares.')
            ->build()];
    }
}
