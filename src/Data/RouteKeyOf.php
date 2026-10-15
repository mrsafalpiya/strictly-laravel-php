<?php

namespace StrictlyLaravel\Data;

use Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * Problem P-003: on a Data property that holds another model's route key, the model it belongs to:
 *
 *     #[RouteKeyOf(Project::class)]
 *     public string $projectKey,
 *
 * The special case of #[KeyOf] (Problem P-128) for the field the routes bind the model by when they name none: it is
 * `#[KeyOf(Project::class, (new Project)->getRouteKeyName())]`, so the property is generated as the model's route key
 * (`Key<'Project', 'key', string>`, which `RouteKey<'Project', string>` names) and can be passed to a route whose
 * action type-hints `Project $project`. `$field` overrides the field, as in #[KeyOf]. A list of keys is branded per
 * item.
 *
 * Feature tests check every value sent for it is an existing key of the model (StrictlyLaravel\Testing\RouteKeyChecks).
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
