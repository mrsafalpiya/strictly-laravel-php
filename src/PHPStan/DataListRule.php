<?php

namespace StrictlyLaravel\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\ClassPropertyNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\VarTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Spatie\LaravelData\Attributes\Hidden;
use Spatie\LaravelData\Contracts\BaseData;
use StrictlyLaravel\Data\InputData;
use StrictlyLaravel\Data\QueryData;

/**
 * Problem P-206: the type generator reads an int-keyed or unkeyed PHPDoc array (`array<int, LabelData>`,
 * `array<LabelData>`, `LabelData[]`) as a list, `LabelData[]`, but PHP promises only int keys, not consecutive ones. A
 * collection that a `filter()` or `unique()` left a gap in keeps its keys through `all()`, and PHP sends such an array
 * as a JSON object (`{"0": …, "2": …}`), so a client that maps over it throws. So a public property of a Data class
 * sent to the browser (a Page, the shared props, the flash data, a JSON response, a broadcast payload, and every Data
 * class they hold) declares such an array a `list<X>` (or `non-empty-list<X>`), which PHPStan proves has no gaps. A
 * map keyed by `string`, `array-key` or an enum's values (`array<value-of<IssueStatus>, int>`) is generated as a
 * `Record` and isn't one.
 *
 * The rule reads the PHPDoc as written, as the generator does: PHPStan resolves `array<LabelData>` and
 * `array<array-key, LabelData>` to the same type, but the generator makes the first a list and the second a map. Input
 * and query classes are left out: their arrays come from the request, which the client builds, not from PHP.
 *
 * @implements Rule<ClassPropertyNode>
 */
final class DataListRule implements Rule
{
    /** The PHPDoc int types, which key an array the generator reads as a list. */
    private const array INT_KEYS = ['int', 'integer', 'positive-int', 'negative-int', 'non-negative-int', 'non-positive-int', 'non-zero-int'];

    public function getNodeType(): string
    {
        return ClassPropertyNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getClassReflection();
        $name = $node->getName();

        if (! $node->isPublic() || $node->isStatic() || ! $class->implementsInterface(BaseData::class)
            || $class->isSubclassOf(InputData::class) || $class->isSubclassOf(QueryData::class)) {
            return [];
        }

        $native = $class->getNativeReflection();

        if (! $native->hasProperty($name) || $native->getProperty($name)->getAttributes(Hidden::class) !== []) {
            return []; // never sent, so never generated
        }

        $declared = $this->declaredType($node, $name);
        $array = $declared === null ? null : $this->intKeyedArray($declared);

        if ($declared === null || $array === null) {
            return [];
        }

        $item = $array instanceof ArrayTypeNode ? $array->type : $array->genericTypes[count($array->genericTypes) - 1];

        return [
            RuleErrorBuilder::message("{$class->getName()}::\${$name} is typed {$declared}, which TypeScript reads as an array, but PHP sends an array whose keys have a gap as a JSON object.")
                ->identifier('strictly.dataList')
                ->tip("Declare it list<{$item}>. A query's ->get()->map(…)->all() is one; after a filter(), unique() or sortBy(), which keep the keys they leave, add ->values() before ->all(), which renumbers them. A map keyed by ints is a JSON object, not an array: key it by string (array<string, {$item}>), or make it a list of Data classes.")
                ->build(),
        ];
    }

    /**
     * The PHPDoc type the generator reads for a property: its own `@var`, else for a promoted property the
     * constructor's `@param`.
     */
    private function declaredType(ClassPropertyNode $node, string $name): ?TypeNode
    {
        foreach ($this->tags($node->getPhpDoc()) as $tag) {
            if ($tag instanceof VarTagValueNode) {
                return $tag->type;
            }
        }

        if (! $node->isPromoted()) {
            return null;
        }

        $constructor = $node->getClassReflection()->getNativeReflection()->getConstructor();
        $docComment = $constructor?->getDocComment();

        foreach ($this->tags($docComment === false ? null : $docComment) as $tag) {
            if ($tag instanceof ParamTagValueNode && $tag->parameterName === "\${$name}") {
                return $tag->type;
            }
        }

        return null;
    }

    /**
     * The `@var` and `@param` tags of a doc comment.
     *
     * @return list<VarTagValueNode|ParamTagValueNode>
     */
    private function tags(?string $docComment): array
    {
        if ($docComment === null || $docComment === '') {
            return [];
        }

        // phpstan/phpdoc-parser, as the generator parses PHPDoc (TypeScript\ClassTemplates): PHPStan's own PhpDoc
        // resolver isn't covered by its backward compatibility promise.
        $config = new ParserConfig(usedAttributes: []);
        $constExprParser = new ConstExprParser($config);
        $parser = new PhpDocParser($config, new TypeParser($config, $constExprParser), $constExprParser);
        $phpDoc = $parser->parse(new TokenIterator((new Lexer($config))->tokenize($docComment)));

        return [...array_values($phpDoc->getVarTagValues()), ...array_values($phpDoc->getParamTagValues())];
    }

    /**
     * The member of a declared type that the generator reads as a list without PHPStan proving it one: `X[]`,
     * `array<X>`, or an `array<int, X>` (`non-empty-array` too). A `list<X>` and a map aren't one.
     */
    private function intKeyedArray(TypeNode $type): ArrayTypeNode|GenericTypeNode|null
    {
        $members = match (true) {
            $type instanceof UnionTypeNode => $type->types,
            $type instanceof NullableTypeNode => [$type->type],
            default => [$type],
        };

        foreach ($members as $member) {
            if ($member instanceof ArrayTypeNode) {
                return $member;
            }

            if (! $member instanceof GenericTypeNode
                || ! in_array(strtolower($member->type->name), ['array', 'non-empty-array'], true)) {
                continue;
            }

            if (count($member->genericTypes) === 1 || $this->isIntKey($member->genericTypes[0])) {
                return $member;
            }
        }

        return null;
    }

    private function isIntKey(TypeNode $key): bool
    {
        return ($key instanceof IdentifierTypeNode && in_array(strtolower($key->name), self::INT_KEYS, true))
            || ($key instanceof GenericTypeNode && strtolower($key->type->name) === 'int'); // int<0, max>
    }
}
