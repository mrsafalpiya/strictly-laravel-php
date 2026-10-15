<?php

namespace StrictlyLaravel\Data;

use Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * Problem P-003: on a Data property that holds another model's route key, the model it belongs to:
 *
 *     #[RouteKeyOf(Project::class)]
 *     public int $project_id,
 *
 * The property is generated as that model's route key (`RouteKey<'Project', number>`), so it can be passed to a route
 * whose action type-hints `Project $project`. A list of keys (`list<int>`) is branded per item. A class's own key
 * needs no attribute: the `id` of a Data class built from a Project (`fromModel(Project $project)`) is branded by
 * itself (StrictlyLaravel\Routes\RouteKeys).
 *
 * Feature tests check every value sent for it is an existing key of the model (StrictlyLaravel\Testing\RouteKeyChecks):
 * a row whose `$field` (the model's route key name unless given) holds it.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class RouteKeyOf
{
    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(
        public string $model,
        public ?string $field = null,
    ) {}
}
