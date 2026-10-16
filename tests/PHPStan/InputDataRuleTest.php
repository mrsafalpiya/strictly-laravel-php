<?php

namespace StrictlyLaravel\Tests\PHPStan;

use PHPStan\Rules\Rule;
use StrictlyLaravel\PHPStan\InputDataRule;

/**
 * @extends StrictlyRuleTestCase<InputDataRule>
 */
final class InputDataRuleTest extends StrictlyRuleTestCase
{
    protected function getRule(): Rule
    {
        return new InputDataRule($this->createReflectionProvider());
    }

    public function test_data_classes_controller_actions_take_extend_input_data(): void
    {
        $tip = 'Make App\Data\Inputs\ProjectData extend StrictlyLaravel\Data\InputData: its rules() then merge with the inferred ones, authorize() runs before validation, and the route is branded with its type. A GET\'s query string extends StrictlyLaravel\Data\QueryData instead.';

        $this->analyse([__DIR__.'/data/controller-inputs.php'], [
            ['App\Http\Controllers\Inputs\ProjectController::update() takes App\Data\Inputs\ProjectData, which is validated from the request but doesn\'t extend StrictlyLaravel\Data\InputData.', 23, $tip],
            ['App\Http\Controllers\Inputs\ProjectController::show() takes App\Data\Inputs\ProjectData, which is validated from the request but doesn\'t extend StrictlyLaravel\Data\InputData.', 42, $tip],
        ]);
    }
}
