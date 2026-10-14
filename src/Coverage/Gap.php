<?php

namespace StrictlyLaravel\Coverage;

/**
 * One thing Contract Coverage found the feature tests never exercised (Problem P-066), with the test that closes it:
 *
 *     ✗ ProjectData.lead is only ever null (at projects/show › project.lead): add a feature test that renders
 *       projects/show with project.lead not null.
 *
 * An unexercised gap (a page no test renders) always fails `strictly:coverage`. An unobserved shape (a state a type
 * permits that no Contract showed) fails it under `--strict`, which is how `ci:check` runs it.
 */
final readonly class Gap
{
    /**
     * @param  string  $what  what was never exercised or observed, such as "ProjectData.lead is only ever null"
     * @param  list<string>  $at  where it was looked for, such as "projects/show › project.lead"
     * @param  string  $fix  the test that closes it, such as "add a feature test that renders projects/show with …"
     * @param  bool  $unexercised  no test reached it at all (fails even without --strict)
     */
    public function __construct(
        public string $what,
        public array $at,
        public string $fix,
        public bool $unexercised,
    ) {}

    public function describe(): string
    {
        $at = $this->at === [] ? '' : ' (at '.implode(', ', $this->at).')';

        return "{$this->what}{$at}: {$this->fix}.";
    }
}
