<?php

namespace StrictlyLaravel\TypeScript;

use BackedEnum;
use Spatie\TypeScriptTransformer\References\ClassStringReference;
use Spatie\TypeScriptTransformer\References\CustomReference;
use Spatie\TypeScriptTransformer\Transformed\Transformed;
use Spatie\TypeScriptTransformer\TransformedProviders\ActionAwareTransformedProvider;
use Spatie\TypeScriptTransformer\TransformedProviders\TransformedProvider;
use Spatie\TypeScriptTransformer\TransformedProviders\TransformedProviderActions;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptRaw;

/**
 * Problem P-034: the values of every backed enum in the given directories, in case order, as a runtime constant next
 * to the generated types, so React iterates `enumValues.IssueStatus` (typed `readonly IssueStatus[]`) instead of
 * `Object.keys(labels) as IssueStatus[]`, which is a cast and wrong for int-backed enums:
 *
 *     export const enumValues = { IssuePriority: [0, 1, 2], IssueStatus: ['backlog', 'todo'] } as const satisfies {…};
 */
final class EnumValuesProvider implements ActionAwareTransformedProvider, TransformedProvider
{
    private TransformedProviderActions $actions;

    /**
     * @param  list<string>  $directories
     */
    public function __construct(private readonly array $directories) {}

    public function setActions(TransformedProviderActions $actions): void
    {
        $this->actions = $actions;
    }

    /**
     * @return list<Transformed>
     */
    public function provide(): array
    {
        $values = [];
        $types = [];
        $references = [];

        foreach ($this->enums() as $name => $enum) {
            $cases = array_map(
                fn (BackedEnum $case): string => json_encode($case->value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $enum::cases(),
            );
            $values[] = "    {$name}: [".implode(', ', $cases).'],';
            $types[] = "    {$name}: readonly %{$name}%[];";
            $references[$name] = new ClassStringReference($enum);
        }

        return [new Transformed(
            new TypeScriptRaw(implode("\n", [
                '/** The values of every backed enum, in case order (iterate these instead of Object.keys()). */',
                'export const enumValues = {',
                ...$values,
                '} as const satisfies {',
                ...$types,
                '};',
            ]), references: $references),
            new CustomReference('strictly', 'enumValues'),
            [],
            false,
        )];
    }

    /**
     * Keyed by TypeScript name, the enum's short name like the type typescript-transformer generates for it.
     *
     * @return array<string, class-string<BackedEnum>>
     */
    private function enums(): array
    {
        $enums = [];

        foreach ($this->actions->discoverTypesAction->execute($this->directories) as $node) {
            $class = $node->getName();

            if (is_subclass_of($class, BackedEnum::class)) {
                $enums[$node->getShortName()] = $class;
            }
        }

        ksort($enums);

        return $enums;
    }
}
