<?php

namespace StrictlyLaravel\Coverage;

/**
 * Where recorded values came from, as Contract Coverage reports it: the label a gap is found at ("projects/show") and
 * what a feature test does to produce it ("renders projects/show"), which the suggested test is built from.
 */
final readonly class Origin
{
    public function __construct(
        public string $label,
        public string $action,
    ) {}

    public static function page(string $component): self
    {
        return new self($component, "renders {$component}");
    }
}
