<?php

namespace StrictlyLaravel\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Spatie\LaravelData\Contracts\BaseData;

/**
 * Only Data classes live in the Data namespace (`App\Data` and below), so everything in it is a shape that crosses a
 * Type Boundary and has a generated TypeScript type. Helpers belong in App\Support, enums in App\Enums. (PHPStan
 * analyses a trait only inside the classes that use it, so a trait there isn't reported.)
 *
 * @implements Rule<InClassNode>
 */
final class DataNamespaceRule implements Rule
{
    public function __construct(private readonly string $dataNamespace = 'App\\Data') {}

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
        $namespace = trim($this->dataNamespace, '\\');

        if ($class->isAnonymous() || ! str_starts_with($class->getName(), $namespace.'\\')
            || ($class->isClass() && $class->implementsInterface(BaseData::class))) {
            return [];
        }

        $kind = match (true) {
            $class->isInterface() => 'Interface',
            $class->isEnum() => 'Enum',
            default => 'Class',
        };

        return [
            RuleErrorBuilder::message("{$kind} {$class->getName()} is in the {$namespace} namespace but is not a Data class.")
                ->identifier('strictly.dataNamespace')
                ->tip("Only laravel-data classes belong in {$namespace}: move helpers to App\\Support and enums to App\\Enums.")
                ->build(),
        ];
    }
}
