<?php

namespace StrictlyLaravel\Tests\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use StrictlyLaravel\PHPStan\InertiaRenderRule;

/**
 * @extends RuleTestCase<InertiaRenderRule>
 */
final class InertiaRenderRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new InertiaRenderRule;
    }

    public function test_pages_are_rendered_only_through_page_classes(): void
    {
        $tip = 'Return a Page class instead, whose constructor defines the props: `php artisan make:page <component>` creates it and its page component.';

        $this->analyse([__DIR__.'/data/inertia-render.php'], [
            ['Inertia::render() renders a page whose props have no generated type.', 15, $tip],
            ['Inertia::render() renders a page whose props have no generated type.', 20, $tip],
            ['inertia() renders a page whose props have no generated type.', 25, $tip],
            ['Inertia\ResponseFactory::render() renders a page whose props have no generated type.', 30, $tip],
            ['Route::inertia() renders a page whose props have no generated type.', 45, $tip],
            ['Illuminate\Routing\Router::inertia() renders a page whose props have no generated type.', 46, $tip],
        ]);
    }
}
