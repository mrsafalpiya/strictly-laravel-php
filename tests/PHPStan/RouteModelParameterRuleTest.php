<?php

namespace StrictlyLaravel\Tests\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use StrictlyLaravel\PHPStan\RouteModelParameterRule;

/**
 * @extends RuleTestCase<RouteModelParameterRule>
 */
final class RouteModelParameterRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new RouteModelParameterRule;
    }

    public function test_only_an_input_takes_route_models_in_authorize_and_rules(): void
    {
        $takes = static fn (string $method, string $parameter): string => sprintf(
            'App\Data\RouteModels\%s takes App\Data\RouteModels\Comment $%s, which is a new, empty Comment there: only an InputData\'s authorize() and rules() receive the route\'s bound models.',
            $method, $parameter,
        );
        $tip = static fn (string $class): string => "Make App\\Data\\RouteModels\\{$class} extend StrictlyLaravel\\Data\\InputData if it is a request's input. Otherwise read the model from request()->route() and narrow it with instanceof.";

        $this->analyse([__DIR__.'/data/route-model-parameters.php'], [
            [$takes('CommentFiltersData::rules()', 'comment'), 57, 'Read the model from request()->route() and narrow it with instanceof: a query class\'s rules() get no route models.'],
            [$takes('CommentData::authorize()', 'comment'), 67, $tip('CommentData')],
            [$takes('BaseCommentData::rules()', 'comment'), 82, $tip('BaseCommentData')],
        ]);
    }
}
