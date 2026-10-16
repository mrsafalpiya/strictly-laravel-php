<?php

namespace StrictlyLaravel\Tests\PHPStan;

use PHPStan\Rules\Rule;
use StrictlyLaravel\PHPStan\RequestHandOffRule;

/**
 * @extends StrictlyRuleTestCase<RequestHandOffRule>
 */
final class RequestHandOffRuleTest extends StrictlyRuleTestCase
{
    protected function getRule(): Rule
    {
        return new RequestHandOffRule('App\\');
    }

    public function test_controllers_and_closure_routes_hand_the_request_to_no_app_code(): void
    {
        $tip = 'Take an input class on the action (a final Data class extending StrictlyLaravel\Data\InputData) and pass it, or the values the code needs (such as $request->user()), instead of the request: the input class types the route\'s form and its validation errors. A GET\'s query string goes in a query class (extending StrictlyLaravel\Data\QueryData) instead.';
        $error = static fn (string $method, string $to, int $line): array => [
            "App\\Http\\Controllers\\RequestHandOffs\\ProjectController::{$method}() hands the request to {$to}, where nothing types the input it reads: a typed form sends only the fields of its action's input class.",
            $line,
            $tip,
        ];

        $this->analyse([__DIR__.'/data/request-hand-offs.php'], [
            $error('store', 'App\\Actions\\RequestHandOffs\\CreateProject::handle()', 116),
            $error('service', 'App\\Actions\\RequestHandOffs\\CreateProject::handle()', 121),
            $error('service', 'new App\\Actions\\RequestHandOffs\\CreateProject()', 121),
            $error('service', 'App\\Actions\\RequestHandOffs\\CreateProject::run()', 121),
            $error('job', 'App\\Jobs\\RequestHandOffs\\ImportProjects::dispatch()', 126),
            $error('closure', 'App\\Actions\\RequestHandOffs\\CreateProject::handle()', 131),
            $error('helper', 'App\\Actions\\RequestHandOffs\\CreateProject::handle()', 136),
            [
                "A closure route hands the request to App\\Actions\\RequestHandOffs\\CreateProject::handle(), where nothing types the input it reads: a typed form sends only the fields of a controller action's input class.",
                168,
                'Move the route to a controller action that takes an input class (a final Data class extending StrictlyLaravel\Data\InputData), and pass the input class, or the values the code needs (such as $request->user()), instead of the request: Wayfinder brands a route function with the input class of the controller action it runs, never with a closure\'s.',
            ],
        ]);
    }
}
