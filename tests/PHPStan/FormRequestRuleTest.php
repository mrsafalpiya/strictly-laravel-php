<?php

namespace StrictlyLaravel\Tests\PHPStan;

use PHPStan\Rules\Rule;
use StrictlyLaravel\PHPStan\FormRequestRule;

/**
 * @extends StrictlyRuleTestCase<FormRequestRule>
 */
final class FormRequestRuleTest extends StrictlyRuleTestCase
{
    protected function getRule(): Rule
    {
        return new FormRequestRule($this->createReflectionProvider());
    }

    public function test_controller_actions_take_an_input_class_instead_of_a_form_request(): void
    {
        $tip = 'Take an input class instead (a final Data class extending StrictlyLaravel\Data\InputData, with the FormRequest\'s rules() and authorize()): it types the route\'s form and its validation errors. A GET\'s query string goes in a query class (extending StrictlyLaravel\Data\QueryData) instead.';
        $error = static fn (string $method, string $request, int $line): array => [
            "App\\Http\\Controllers\\FormRequests\\ProjectController::{$method}() takes App\\Http\\Requests\\FormRequests\\{$request}, a FormRequest, where nothing types its payload: a typed form sends only the fields of its action's input class.",
            $line,
            $tip,
        ];

        $this->analyse([__DIR__.'/data/form-requests.php'], [
            $error('store', 'StoreProjectRequest', 33),
            $error('update', 'UpdateProjectRequest', 37),
        ]);
    }
}
