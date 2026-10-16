<?php

namespace StrictlyLaravel\Install;

/**
 * What a hook says about an app setting that switches a Strictly Laravel rule off or ignores its errors (P-190): the
 * Overlay has no toggles, only a suppression on the line with a reason (ADR-0007).
 */
final class NoToggles
{
    /**
     * @param  string  $remove  what the app removes: `the setting`, `the entry`
     * @param  string  $suppression  how it suppresses a single line instead, with its reason
     */
    public static function message(string $remove, string $suppression): string
    {
        return "Strictly Laravel has no toggles (ADR-0007): remove {$remove}, and suppress single lines with `{$suppression}`.";
    }
}
