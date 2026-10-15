<?php

namespace StrictlyLaravel\Routes;

use Illuminate\Database\Eloquent\Model;

/**
 * Which model's key a value is, and the field (column) it is a key of and looked up by (RouteKeys): a route key, or
 * any other model key (Problem P-128).
 */
final readonly class RouteKey
{
    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(
        public string $model,
        public string $field,
    ) {}

    /** The model's name in the brand: `Issue` in `Key<'Issue', 'id', number>`. */
    public function brand(): string
    {
        return class_basename($this->model);
    }

    /**
     * The TypeScript type of the key, `import('@mrsafalpiya/strictly-laravel/routes').Key<'Issue', 'id', number>`, for
     * a value of the given TypeScript type (`number`, `string`, `string | number`).
     */
    public function typeScript(string $value): string
    {
        return sprintf(
            "import('%s').Key<'%s', '%s', %s>",
            WayfinderTemplates::TYPES_MODULE,
            $this->brand(),
            str_replace(['\\', "'"], ['\\\\', "\\'"], $this->field),
            $value,
        );
    }
}
