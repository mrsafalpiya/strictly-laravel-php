<?php

namespace StrictlyLaravel\Tests\PHPStan;

use PHPStan\Rules\Rule;
use StrictlyLaravel\PHPStan\KeyOfRule;

/**
 * @extends StrictlyRuleTestCase<KeyOfRule>
 */
final class KeyOfRuleTest extends StrictlyRuleTestCase
{
    protected function getRule(): Rule
    {
        return new KeyOfRule($this->createReflectionProvider());
    }

    public function test_a_property_holding_a_model_key_declares_the_model(): void
    {
        $named = static fn (string $property): string => "App\\Data\\Keys\\{$property} is named like a model's key but declares no model, so TypeScript takes any value for it: another model's key, or another field's, fits.";
        $validated = static fn (string $property): string => "App\\Data\\Keys\\{$property} is validated by an exists rule but declares no model, so TypeScript takes any value for it: another model's key, or another field's, fits.";
        $tip = "Declare the model it is a key of: #[KeyOf(User::class)] (StrictlyLaravel\\Data\\KeyOf) for its primary key, #[KeyOf(Issue::class, 'number')] for another field. A value that is no model's key, such as another service's id, takes @phpstan-ignore strictly.keyOf (the reason).";

        $this->analyse([__DIR__.'/data/model-keys.php'], [
            [$named('TicketData::$reporter_id'), 40, $tip],
            [$named('TicketData::$project_id'), 47, $tip],
            [$named('TicketData::$watcherIds'), 53, $tip],
            [$named('TicketData::$label_ids'), 55, $tip],
            [$validated('StoreTicketData::$ids'), 71, $tip],
            [$validated('StoreTicketData::$parent'), 72, $tip],
            [$validated('StoreTicketData::$reviewer'), 73, $tip],
            [$validated('StoreTicketData::$tags'), 76, $tip],
            [$named('StoreTicketData::$assigneeId'), 81, $tip],
            [$named('TicketFiltersData::$assigneeId'), 109, $tip],
        ]);
    }
}
