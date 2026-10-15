<?php

namespace StrictlyLaravel\Data;

use Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * Problem P-128: on a Data property that holds a model's key, the model it belongs to, and the field it is a key of
 * (the model's primary key unless given):
 *
 *     #[KeyOf(User::class)]
 *     public ?int $assigneeId,
 *
 *     #[KeyOf(Issue::class)]
 *     public array $ids,          // list<int>
 *
 *     #[KeyOf(Issue::class, 'number')]
 *     public int $issueNumber,
 *
 * The property is generated as that model's key, `Key<'User', 'id', number>` (per item for a list), in input, query and
 * output classes alike. So an input's `assigneeId` takes a member's `id` but not an issue's, and not an issue's
 * `number` where its `id` is expected. A class's own primary key needs no attribute: the `id` of a Data class built from
 * a model (`fromModel(Issue $issue)`) is branded by itself (StrictlyLaravel\Routes\RouteKeys). A property named like a
 * key (`…Id`, `…Ids`, `…_id`, `…_ids`) or validated by an `exists` rule must declare it (strictly.keyOf, KeyOfRule).
 *
 * Feature tests check every value the server sends for it is an existing key of the model
 * (StrictlyLaravel\Testing\RouteKeyChecks): a row whose `$field` holds it.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class KeyOf
{
    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(
        public string $model,
        public ?string $field = null,
    ) {}
}
