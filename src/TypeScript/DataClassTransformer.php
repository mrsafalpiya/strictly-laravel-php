<?php

namespace StrictlyLaravel\TypeScript;

use Spatie\LaravelTypeScriptTransformer\LaravelData\Transformers\DataClassTransformer as LaravelDataClassTransformer;
use Spatie\TypeScriptTransformer\Data\TransformationContext;
use Spatie\TypeScriptTransformer\PhpNodes\PhpClassNode;
use Spatie\TypeScriptTransformer\Transformed\Transformed;
use Spatie\TypeScriptTransformer\Transformed\Untransformable;
use StrictlyLaravel\Routes\RouteKeys;
use StrictlyLaravel\Workarounds\TypeScriptTransformer\EnumKeyedMaps;
use StrictlyLaravel\Workarounds\TypeScriptTransformer\HiddenDataProperties;
use StrictlyLaravel\Workarounds\TypeScriptTransformer\LiteralTypes;
use StrictlyLaravel\Workarounds\TypeScriptTransformer\TemplateBounds;

/**
 * laravel-typescript-transformer's transformer for laravel-data classes, with the Overlay's fixes to PHPDoc types and
 * to properties, a generic Data class's template bounds (TemplateBounds), and a model's route keys branded by the model
 * (RouteKeyTypes). It replaces the stock one, so every Data class is transformed the same way.
 */
final class DataClassTransformer extends LaravelDataClassTransformer
{
    public function __construct()
    {
        parent::__construct(transpilePhpStanTypeToTypeScriptTypeAction: self::phpDocTypes());
    }

    /**
     * How a Data class's PHPDoc types are transpiled to TypeScript, with the Overlay's fixes. Anything else generated
     * from a PHPDoc type that names Data classes, such as a JSON endpoint's `@return ListingData<IssueData>`
     * (Routes\RouteResponse), transpiles it with this, so it agrees with the generated types.
     */
    public static function phpDocTypes(): PhpDocTypeTranspiler
    {
        return new PhpDocTypeTranspiler([
            new LiteralTypes,
            new EnumKeyedMaps,
        ]);
    }

    public function transform(PhpClassNode $phpClassNode, TransformationContext $context): Transformed|Untransformable
    {
        $transformed = parent::transform($phpClassNode, $context);

        if ($transformed instanceof Transformed) {
            TemplateBounds::apply($transformed, $phpClassNode, $this->transpilePhpStanTypeToTypeScriptTypeAction);
        }

        return $transformed;
    }

    protected function classPropertyProcessors(): array
    {
        return [
            new HiddenDataProperties,
            new DateOnlyTypes,
            ...parent::classPropertyProcessors(),
            // Last, on the final type: brands its number or string (`?int` → `Key<'Project', 'id', number> | null`).
            new RouteKeyTypes(fn (): RouteKeys => RouteKeys::of(app('router'))),
        ];
    }
}
