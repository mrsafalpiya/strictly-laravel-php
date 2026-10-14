<?php

namespace StrictlyLaravel\Install;

use RuntimeException;
use StrictlyLaravel\Testing\RecordsContracts;

/**
 * Makes the app's feature tests record Contracts (Problem P-065) by applying RecordsContracts in tests/Pest.php. Kit
 * Conversion writes it into a tests/Pest.php it converts; this covers one it skipped because the app had edited it,
 * importing the trait among the file's imports (in the order Pint keeps them) and applying it at the end.
 */
final class PestContractsHook
{
    private const string IMPORT = 'use '.RecordsContracts::class.';';

    /**
     * Returns whether the file changed.
     */
    public static function ensure(string $pestFile): bool
    {
        if (! is_file($pestFile)) {
            throw new RuntimeException('tests/Pest.php not found: create it with `pest()->use(\\'.RecordsContracts::class."::class)->in('Feature');`");
        }

        $original = str_replace("\r\n", "\n", (string) file_get_contents($pestFile));

        if (str_contains($original, 'RecordsContracts::class')) {
            return false;
        }

        if (! str_starts_with($original, "<?php\n")) {
            throw new RuntimeException('tests/Pest.php does not start with `<?php`; add `pest()->use(\\'.RecordsContracts::class."::class)->in('Feature');` by hand");
        }

        $updated = rtrim(self::withImport($original), "\n")."\n\n".implode("\n", [
            '// Every page a feature test renders is a Contract, checked by tsc (Strictly Laravel).',
            "pest()->use(RecordsContracts::class)->in('Feature');",
        ])."\n";

        file_put_contents($pestFile, $updated);

        return true;
    }

    private static function withImport(string $source): string
    {
        if (str_contains($source, self::IMPORT)) {
            return $source;
        }

        $lines = explode("\n", $source);
        $imports = array_keys(array_filter($lines, fn (string $line): bool => preg_match('/^use [^;]+;$/', $line) === 1));

        if ($imports === []) {
            return "<?php\n\n".self::IMPORT."\n".substr($source, strlen("<?php\n"));
        }

        // Before the first import that sorts after it, or after the last one.
        $at = end($imports) + 1;

        foreach ($imports as $index) {
            if (strcasecmp($lines[$index], self::IMPORT) > 0) {
                $at = $index;

                break;
            }
        }

        array_splice($lines, $at, 0, [self::IMPORT]);

        return implode("\n", $lines);
    }
}
