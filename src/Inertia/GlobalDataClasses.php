<?php

namespace StrictlyLaravel\Inertia;

use LogicException;
use Spatie\TypeScriptTransformer\Actions\DiscoverTypesAction;

/**
 * The app's shared props and flash Data classes: its one concrete subclass of Shared and of Flash, if it has one.
 * Type generation writes them into Inertia's global config typing (InertiaConfigProvider), and Contract Coverage
 * proves their shapes against the recorded Contracts.
 */
final readonly class GlobalDataClasses
{
    /**
     * @param  class-string<Shared>|null  $shared
     * @param  class-string<Flash>|null  $flash
     */
    public function __construct(
        public ?string $shared,
        public ?string $flash,
    ) {}

    /**
     * @param  list<string>  $directories  where the app's classes live (app/)
     */
    public static function in(array $directories): self
    {
        $classes = [];

        foreach ((new DiscoverTypesAction)->execute($directories) as $node) {
            if (! $node->isAbstract()) {
                $classes[] = $node->getName();
            }
        }

        return self::among($classes);
    }

    /**
     * @param  list<string>  $classes  concrete classes
     */
    public static function among(array $classes): self
    {
        sort($classes);

        return new self(self::onlySubclassOf($classes, Shared::class), self::onlySubclassOf($classes, Flash::class));
    }

    /**
     * @template T of object
     *
     * @param  list<string>  $classes
     * @param  class-string<T>  $base
     * @return class-string<T>|null
     */
    private static function onlySubclassOf(array $classes, string $base): ?string
    {
        $found = array_values(array_filter($classes, fn (string $class): bool => is_subclass_of($class, $base)));

        if (count($found) > 1) {
            throw new LogicException("Only one class may extend {$base}, found ".implode(', ', $found).'.');
        }

        return $found[0] ?? null;
    }
}
