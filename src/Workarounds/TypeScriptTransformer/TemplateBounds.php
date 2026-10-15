<?php

namespace StrictlyLaravel\Workarounds\TypeScriptTransformer;

use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use Spatie\TypeScriptTransformer\Actions\TranspilePhpStanTypeToTypeScriptNodeAction;
use Spatie\TypeScriptTransformer\PhpNodes\PhpClassNode;
use Spatie\TypeScriptTransformer\References\ClassStringReference;
use Spatie\TypeScriptTransformer\Transformed\Transformed;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptAlias;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptGeneric;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptGenericTypeParameter;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptIdentifier;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptNode;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptObject;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptReference;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptUnion;

/**
 * Problem P-062 (docs/problems.md). Upstream: no upstream issue filed (typescript-transformer 3.3's DocTypeResolver
 * keeps only a `@template`'s name).
 *
 * A generic Data class (`@template TItem of IssueData`) is generated as `ListingData<TItem>`, so TypeScript code may
 * instantiate it with any type. This writes each template's bound as its type parameter's constraint
 * (`ListingData<TItem extends IssueData>`). laravel-data's own base classes have no TypeScript type, so a bound on one
 * of them (`of Data`) is written as `object`. Delete this class once typescript-transformer writes template bounds.
 */
final class TemplateBounds
{
    public static function apply(Transformed $transformed, PhpClassNode $class, TranspilePhpStanTypeToTypeScriptNodeAction $transpiler): void
    {
        $alias = $transformed->getNode();

        if (! $alias instanceof TypeScriptAlias || ! $alias->identifier instanceof TypeScriptGeneric) {
            return;
        }

        $templates = self::templates($class);
        $names = array_keys($templates);

        foreach ($alias->identifier->genericTypes as $parameter) {
            if (! $parameter instanceof TypeScriptGenericTypeParameter || ! $parameter->identifier instanceof TypeScriptIdentifier) {
                continue;
            }

            $bound = ($templates[$parameter->identifier->name] ?? null)?->bound;

            if ($bound !== null) {
                $parameter->extends = self::withoutLaravelDataBases($transpiler->execute($bound, $class, $names));
            }
        }
    }

    /**
     * @return array<string, TemplateTagValueNode>
     */
    private static function templates(PhpClassNode $class): array
    {
        $docComment = $class->getDocComment();

        if ($docComment === null) {
            return [];
        }

        $config = new ParserConfig(usedAttributes: []);
        $constExprParser = new ConstExprParser($config);
        $parser = new PhpDocParser($config, new TypeParser($config, $constExprParser), $constExprParser);
        $doc = $parser->parse(new TokenIterator((new Lexer($config))->tokenize($docComment)));

        $templates = [];

        foreach (['@template', '@template-covariant', '@template-contravariant'] as $tag) {
            foreach ($doc->getTemplateTagValues($tag) as $template) {
                $templates[$template->name] = $template;
            }
        }

        return $templates;
    }

    private static function withoutLaravelDataBases(TypeScriptNode $node): TypeScriptNode
    {
        if ($node instanceof TypeScriptUnion) {
            return new TypeScriptUnion(array_map(self::withoutLaravelDataBases(...), $node->types));
        }

        if (
            $node instanceof TypeScriptReference
            && $node->reference instanceof ClassStringReference
            && str_starts_with($node->reference->classString, 'Spatie\\LaravelData\\')
        ) {
            return new TypeScriptObject([]);
        }

        return $node;
    }
}
