<?php

namespace StrictlyLaravel\PHPStan;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\TypeCombinator;
use Spatie\LaravelData\Data;
use StrictlyLaravel\Broadcasting\BroadcastsData;
use StrictlyLaravel\Inertia\Page;

/**
 * Problem P-052: a broadcast event's payload is a Data class. The event uses BroadcastsData and returns the payload
 * from `broadcastData()`, declaring its one Data class as the return type, which `strictly:generate` maps the event's
 * name to and the broadcast Contracts are checked against. Every other way to send a payload has no generated type,
 * so it is rejected: an event without BroadcastsData (its public properties or a `broadcastWith()` array), one that
 * replaces the trait's `broadcastWith()`, and a `broadcastData()` that doesn't declare one Data class.
 *
 * @implements Rule<InClassNode>
 */
final readonly class BroadcastDataRule implements Rule
{
    public function __construct(
        private ReflectionProvider $reflection,
    ) {}

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

        $name = $class->getName();

        if (! $class->hasTraitUse(BroadcastsData::class)) {
            return [$this->error(
                "Broadcast event {$name} sends a payload that has no generated type.",
                'Use StrictlyLaravel\Broadcasting\BroadcastsData and return the payload from broadcastData(), declaring its Data class: `public function broadcastData(): CommentData`.',
            )];
        }

        $errors = [];

        foreach ($node->getOriginalNode()->getMethods() as $method) {
            if ($method->name->toLowerString() === 'broadcastwith') {
                $errors[] = $this->error(
                    "{$name}::broadcastWith() replaces the payload broadcastData() declares.",
                    'Remove it: BroadcastsData broadcasts what broadcastData() returns, which its Data class types.',
                );
            }
        }

        if (! $this->declaresDataClass($class)) {
            $errors[] = $this->error(
                "{$name}::broadcastData() must declare the one Data class its payload is as its return type.",
                'Return a Data class that isn\'t a Page class, such as `public function broadcastData(): CommentData`: its generated type types the listeners.',
            );
        }

        return $errors;
    }

    private function declaresDataClass(ClassReflection $class): bool
    {
        if (! $class->hasNativeMethod('broadcastData')) {
            return false;
        }

        $variants = $class->getNativeMethod('broadcastData')->getVariants();
        $type = count($variants) === 1 ? $variants[0]->getNativeReturnType() : null;
        $names = $type === null || TypeCombinator::containsNull($type) ? [] : $type->getObjectClassNames();

        if (count($names) !== 1 || ! $this->reflection->hasClass($names[0])) {
            return false;
        }

        $payload = $this->reflection->getClass($names[0]);

        return $payload->isSubclassOf(Data::class) && ! $payload->isSubclassOf(Page::class);
    }

    private function error(string $message, string $tip): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)->identifier('strictly.broadcastData')->tip($tip)->build();
    }
}
