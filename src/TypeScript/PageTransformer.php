<?php

namespace StrictlyLaravel\TypeScript;

use Illuminate\Support\Str;
use LogicException;
use Spatie\TypeScriptTransformer\Actions\TransformTypesAction;
use Spatie\TypeScriptTransformer\Data\TransformationContext;
use Spatie\TypeScriptTransformer\PhpNodes\PhpClassNode;
use Spatie\TypeScriptTransformer\Transformed\Transformed;
use Spatie\TypeScriptTransformer\Transformed\Untransformable;
use Spatie\TypeScriptTransformer\Transformers\Transformer;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptAlias;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptIdentifier;
use StrictlyLaravel\Inertia\Page;
use StrictlyLaravel\Inertia\PageLocations;

/**
 * Names each Page class's generated type after its page component (`App\Pages\Settings\ProfilePage` becomes
 * `SettingsProfilePage`), so the `ShowPage` of two resources don't produce two types with the same name. The props
 * themselves are transformed by the rest of the transformer chain, like any Data class, so every fix it carries
 * applies to page props too. It must come first in the chain: TypeGeneration prepends it last and then hands it the
 * chain (delegateTo()).
 */
final class PageTransformer implements Transformer
{
    /** @var list<Transformer>|null */
    private ?array $transformers = null;

    /**
     * @param  array<Transformer>  $transformers  every other transformer, in order
     */
    public function delegateTo(array $transformers): void
    {
        $this->transformers = array_values(array_filter($transformers, fn (Transformer $transformer): bool => $transformer !== $this));
    }

    public function transform(PhpClassNode $phpClassNode, TransformationContext $context): Transformed|Untransformable
    {
        $class = $phpClassNode->getName();

        if ($phpClassNode->isAbstract() || ! is_subclass_of($class, Page::class)) {
            return Untransformable::create();
        }

        if ($this->transformers === null) {
            throw new LogicException('PageTransformer has no transformers to delegate to; call delegateTo() with the config\'s transformers.');
        }

        $transformed = (new TransformTypesAction)->transformClassNode($this->transformers, $phpClassNode);
        $alias = $transformed?->getNode();

        if ($transformed === null || ! $alias instanceof TypeScriptAlias) {
            throw new LogicException("Could not transform the Page class {$class} to a TypeScript type.");
        }

        $name = self::typeScriptName($class);
        $alias->identifier = new TypeScriptIdentifier($name);
        $transformed->nameAs($name);

        return $transformed;
    }

    /**
     * The name of a Page class's generated type, such as "SettingsProfilePage": unique per page, where the class's
     * short name (ProfilePage, ShowPage) is not.
     *
     * @param  class-string  $class
     */
    private static function typeScriptName(string $class): string
    {
        return Str::of(PageLocations::componentFor($class))
            ->explode('/')
            ->map(fn (string $segment): string => Str::studly($segment))
            ->implode('').PageLocations::SUFFIX;
    }
}
