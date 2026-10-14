<?php

namespace StrictlyLaravel\Tests\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use StrictlyLaravel\PHPStan\InertiaFlashRule;

/**
 * @extends RuleTestCase<InertiaFlashRule>
 */
final class InertiaFlashRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new InertiaFlashRule;
    }

    public function test_flash_data_is_written_only_through_the_flash_data_class(): void
    {
        $tip = 'Flash through the app\'s flash Data class instead (App\Data\FlashData, which extends StrictlyLaravel\Inertia\Flash): `(new FlashData(...))->flash()`.';

        $this->analyse([__DIR__.'/data/inertia-flash.php'], [
            ['Inertia::flash() flashes data that has no generated type.', 15, $tip],
            ['Inertia\ResponseFactory::flash() flashes data that has no generated type.', 20, $tip],
            ['Inertia\Response::flash() flashes data that has no generated type.', 25, $tip],
        ]);
    }
}
