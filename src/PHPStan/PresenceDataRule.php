<?php

namespace StrictlyLaravel\PHPStan;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;
use Spatie\LaravelData\Data;

/**
 * Problem P-145: a presence channel's callback in routes/channels.php returns the data each member shares with the
 * others, which `strictly:generate` types the channel's members from, so `usePresence()`'s `here`, `joining` and
 * `leaving` receive the Data class the callback declares as its return type (`fn (User $user, Issue $issue): ?ViewerData
 * => …`). A callback that returns an array (Laravel's documented way) shares data nothing types, and one that returns a
 * Data class without declaring it can't be told from a private channel when the app's channels are read, so both are
 * rejected, on `Broadcast::channel()` and a broadcaster's `channel()`, for a closure or a channel class's `join()`.
 * A callback returning `bool` (a private channel) is untouched.
 *
 * @implements Rule<CallLike>
 */
final readonly class PresenceDataRule implements Rule
{
    private const string BROADCAST_FACADE = 'Illuminate\Support\Facades\Broadcast';

    public function __construct(
        private ReflectionProvider $reflectionProvider,
    ) {}

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $this->definesChannel($node, $scope) || $node->isFirstClassCallable()) {
            return [];
        }

        $args = $node->getArgs();

        if (count($args) < 2) {
            return [];
        }

        $patterns = $scope->getType($args[0]->value)->getConstantStrings();
        $channel = count($patterns) === 1 ? "Presence channel {$patterns[0]->getValue()}" : 'A presence channel';
        $returned = $this->returnType($args[1]->value, $scope);

        if ($returned === null) {
            return [];
        }

        [$type, $declared] = $returned;
        $members = $type instanceof UnionType ? $type->getTypes() : [$type];

        foreach ($members as $member) {
            if ($member->isArray()->yes()) {
                return [RuleErrorBuilder::message("{$channel} returns its members' data as an array.")
                    ->identifier('strictly.presenceData')
                    ->tip('Return a Data class instead, declared as the callback\'s return type (fn (User $user, Issue $issue): ?ViewerData => …): usePresence() types the members from it.')
                    ->build()];
            }
        }

        foreach ($declared ? [] : $members as $member) {
            if ((new ObjectType(Data::class))->isSuperTypeOf($member)->yes()) {
                return [RuleErrorBuilder::message("{$channel} returns a Data class its callback doesn't declare as its return type.")
                    ->identifier('strictly.presenceData')
                    ->tip('Declare it (fn (User $user, Issue $issue): ?ViewerData => …): strictly:generate reads the declared return type to type the members, and takes a channel without one for a private channel.')
                    ->build()];
            }
        }

        return [];
    }

    /**
     * `Broadcast::channel(…)`, or `channel(…)` on a broadcaster.
     */
    private function definesChannel(Node $node, Scope $scope): bool
    {
        if ($node instanceof StaticCall) {
            return $node->class instanceof Name && $node->name instanceof Identifier
                && $scope->resolveName($node->class) === self::BROADCAST_FACADE
                && $node->name->toLowerString() === 'channel';
        }

        return $node instanceof MethodCall && $node->name instanceof Identifier && $node->name->toLowerString() === 'channel'
            && (new ObjectType(Broadcaster::class))->isSuperTypeOf($scope->getType($node->var))->yes();
    }

    /**
     * What the channel's callback returns, and whether it declares that type: a closure's, or a channel class's
     * `join()`'s (a method always declares one, at PHPStan max).
     *
     * @return array{Type, bool}|null
     */
    private function returnType(Expr $callback, Scope $scope): ?array
    {
        if ($callback instanceof Closure || $callback instanceof ArrowFunction) {
            $acceptors = $scope->getType($callback)->getCallableParametersAcceptors($scope);

            return $acceptors === [] ? null : [$acceptors[0]->getReturnType(), $callback->returnType !== null];
        }

        foreach ($scope->getType($callback)->getConstantStrings() as $class) {
            if ($this->reflectionProvider->hasClass($class->getValue())) {
                $reflection = $this->reflectionProvider->getClass($class->getValue());

                if ($reflection->hasMethod('join')) {
                    $variants = $reflection->getMethod('join', $scope)->getVariants();

                    return $variants === [] ? null : [$variants[0]->getReturnType(), true];
                }
            }
        }

        return null;
    }
}
