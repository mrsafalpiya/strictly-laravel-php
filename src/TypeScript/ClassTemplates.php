<?php

namespace StrictlyLaravel\TypeScript;

use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use Spatie\TypeScriptTransformer\PhpNodes\PhpClassNode;

/**
 * The `@template` tags of a generic class (`@template TItem of IssueData`), in the order its type arguments are written,
 * with their bounds. A generic Data class is generated with these as its TypeScript type parameters
 * (Workarounds\TypeScriptTransformer\TemplateBounds writes their bounds), and Contract Coverage binds them to the type
 * arguments of each use (Coverage\TemplateArgument).
 */
final class ClassTemplates
{
    private const array TAGS = ['@template', '@template-covariant', '@template-contravariant'];

    /**
     * @return array<string, TemplateTagValueNode> by template name, in the order they are declared
     */
    public static function of(PhpClassNode $class): array
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

        foreach ($doc->children as $child) {
            if ($child instanceof PhpDocTagNode && in_array($child->name, self::TAGS, true) && $child->value instanceof TemplateTagValueNode) {
                $templates[$child->value->name] = $child->value;
            }
        }

        return $templates;
    }
}
