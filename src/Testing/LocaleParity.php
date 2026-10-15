<?php

namespace StrictlyLaravel\Testing;

use PHPUnit\Framework\Assert;
use StrictlyLaravel\Translations\Translations;

/**
 * Problem P-057: every locale other than the default must have exactly the default locale's translation keys, each
 * with the same `:params`. `t()` is typed by the default locale only, so a key another locale lacks shows its users
 * the default locale's text, and a param it lacks or adds goes unfilled. The app's tests/Feature/LocaleParityTest.php
 * (published by `strictly:install`) runs this.
 */
final class LocaleParity
{
    public static function assert(): void
    {
        $translations = Translations::ofApp();
        $problems = $translations->parityProblems();

        Assert::assertSame([], $problems, "These translations don't match the default locale ({$translations->defaultLocale}), "
            .'which types t(), so their users would see its text or an unfilled :param (Problem P-057). Add, rename or '
            ."remove each one in lang/:\n  ".implode("\n  ", $problems));
    }
}
