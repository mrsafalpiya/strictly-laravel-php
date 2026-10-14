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
 * Data classes are final: a Data class's generated TypeScript type describes exactly that class, so a subclass, sent
 * where the parent is declared, would carry props the type doesn't have. An abstract Data class is never sent itself.
 *
 * @implements Rule<InClassNode>
 */
final class DataClassFinalRule implements Rule
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

        if (! $class->isClass() || $class->isAnonymous() || $class->isAbstract() || $class->isFinalByKeyword()
            || ! $class->implementsInterface(BaseData::class)) {
            return [];
        }

        return [
            RuleErrorBuilder::message("Data class {$class->getName()} must be final.")
                ->identifier('strictly.dataClassFinal')
                ->tip("Its generated TypeScript type describes exactly this class; a subclass would send props the type doesn't declare.")
                ->build(),
        ];
    }
}
