<?php

namespace StrictlyLaravel\Tests\PHPStan;

use PHPStan\Rules\Rule;
use StrictlyLaravel\PHPStan\AuthorizeInInputRule;

/**
 * @extends StrictlyRuleTestCase<AuthorizeInInputRule>
 */
final class AuthorizeInInputRuleTest extends StrictlyRuleTestCase
{
    protected function getRule(): Rule
    {
        return new AuthorizeInInputRule;
    }

    public function test_actions_taking_an_input_authorize_in_it_not_in_their_body(): void
    {
        $tip = 'Authorize in App\Data\Inputs\StoreProjectData::authorize() instead (its parameters are injected), which runs before validation.';

        $this->analyse([__DIR__.'/data/controller-inputs.php'], [
            ['App\Http\Controllers\Inputs\ProjectController::destroy() authorizes with Gate::authorize() after App\Data\Inputs\StoreProjectData was validated, so an unauthorized user gets a 422 listing the fields instead of a 403.', 29, $tip],
            ['App\Http\Controllers\Inputs\ProjectController::archive() authorizes with $this->authorize() after App\Data\Inputs\StoreProjectData was validated, so an unauthorized user gets a 422 listing the fields instead of a 403.', 34, $tip],
            ['App\Http\Controllers\Inputs\ProjectController::restore() authorizes with abort_unless(…, 403) after App\Data\Inputs\StoreProjectData was validated, so an unauthorized user gets a 422 listing the fields instead of a 403.', 39, $tip],
        ]);
    }
}
