<?php

namespace StrictlyLaravel\Routes;

use Illuminate\Database\Eloquent\Model;

/**
 * Which model's route key a value is, and the column it is looked up by (RouteKeys).
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

    /** The brand TypeScript gives the key: `RouteKey<'Project', number>`. */
    public function brand(): string
    {
        return class_basename($this->model);
    }
}
