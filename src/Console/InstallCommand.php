<?php

namespace StrictlyLaravel\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use StrictlyLaravel\Install\BoostUpdateScript;
use StrictlyLaravel\Install\CiCheckScripts;
use StrictlyLaravel\Install\ConversionOutcome;
use StrictlyLaravel\Install\GenerateFirst;
use StrictlyLaravel\Install\GitignoreEntry;
use StrictlyLaravel\Install\JsxTypes;
use StrictlyLaravel\Install\KitConversion;
use StrictlyLaravel\Install\PackageManager;
use StrictlyLaravel\Install\PestContractsHook;
use StrictlyLaravel\Install\PhpstanIgnores;
use StrictlyLaravel\Install\PhpstanInclude;
use StrictlyLaravel\Install\ScratchCopy;
use StrictlyLaravel\Install\TsconfigFlags;
use StrictlyLaravel\Install\ViteLintPlugin;
use StrictlyLaravel\Install\ViteTypesPlugin;
use StrictlyLaravel\StrictlyServiceProvider;
use StrictlyLaravel\Testing\ContractRecorder;
use StrictlyLaravel\TypeScript\TypeGeneration;
use StrictlyLaravel\Workarounds\WayfinderQueryVerb;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Applies the Overlay's hooks and Kit Conversion to the app. Idempotent and non-interactive: running it again changes
 * nothing, and it never overwrites a file the app owns (such as an existing config/strictly.php, or a Base Kit file
 * whose content Kit Conversion doesn't recognise). Run again after updating the packages, it applies whatever a newer
 * release adds and reports what it can't apply (Problem P-080).
 *
 * `--check` changes nothing: it applies everything to a scratch copy of the app (ScratchCopy) and fails when anything
 * would change or can't be applied. `ci:check` runs it first, so an app that lost a hook, hasn't picked up one a newer
 * release adds, or hasn't made a change a release needs by hand fails until it is fixed (Problems P-080 and P-082; the
 * release checks of the upgrade guide).
 */
#[AsCommand(name: 'strictly:install')]
final class InstallCommand extends Command
{
    /** Where an installed app finds the upgrade guide. */
    public const string UPGRADE_GUIDE = 'vendor/mrsafalpiya/strictly-laravel/UPGRADE.md';

    protected $signature = 'strictly:install
        {--check : Change nothing; fail when the installer would change something or can\'t apply a hook}';

    protected $description = 'Apply the Strictly Laravel Overlay to this app (safe to run again)';

    private bool $checking = false;

    /**
     * The rows that failed or, checking, would change something.
     *
     * @var list<string>
     */
    private array $incomplete = [];

    public function handle(Filesystem $files): int
    {
        $this->checking = $this->option('check');

        if (! $this->checking) {
            $this->apply($this->laravel->basePath(), $files);

            return $this->incomplete === [] ? self::SUCCESS : self::FAILURE;
        }

        $scratch = ScratchCopy::create($this->laravel->basePath());

        try {
            $this->apply($scratch, $files);
        } finally {
            ScratchCopy::remove($scratch);
        }

        if ($this->incomplete !== []) {
            $this->components->error('Strictly Laravel is not fully applied to this app: '.implode('; ', $this->incomplete)
                .'. Run `php artisan strictly:install`, then make by hand each change it reports it can\'t apply ('
                .self::UPGRADE_GUIDE.' lists what each release needs).');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Every hook, labelled with its row. A hook edits the app at $root, never base_path() (`--check` runs it on a scratch
     * copy), returns whether it changed anything, and throws when it can't be applied, saying what to do by hand. A hook
     * added here reaches every app installed before it existed the next time the installer runs, and until then the
     * app's `ci:check` fails on it.
     *
     * @return array<string, callable(): bool>
     */
    private function hooks(string $root, Filesystem $files): array
    {
        $packageManager = PackageManager::detect($root);

        return [
            'config/strictly.php' => fn () => $this->publish($files, StrictlyServiceProvider::CONFIG_STUB, "{$root}/config/strictly.php"),
            'tests/Feature/LocaleParityTest.php' => fn () => $this->publish($files, StrictlyServiceProvider::LOCALE_PARITY_TEST_STUB, "{$root}/tests/Feature/LocaleParityTest.php"),
            "Composer scripts: ci:check and its steps (using {$packageManager->value})" => fn () => $this->writeCiCheck("{$root}/composer.json", $packageManager),
            'Composer scripts: boost:update after composer update' => fn () => BoostUpdateScript::ensure("{$root}/composer.json"),
            'tsconfig.json: strictness flags' => fn () => TsconfigFlags::patch("{$root}/tsconfig.json"),
            'tsconfig.json: narrowed JSX types' => fn () => JsxTypes::patchTsconfig("{$root}/tsconfig.json"),
            'vite.config.ts: Strictly Laravel oxlint plugin' => fn () => ViteLintPlugin::register("{$root}/vite.config.ts"),
            'vite.config.ts: oxlint rejects assertions and unsafe any' => fn () => ViteLintPlugin::rejectAssertionsAndUnsafeAny("{$root}/vite.config.ts"),
            'vite.config.ts: Strictly Laravel Vite plugin' => fn () => ViteTypesPlugin::register("{$root}/vite.config.ts"),
            "vite.config.ts: React plugin keeps React's JSX runtime" => fn () => JsxTypes::pinViteReactRuntime("{$root}/vite.config.ts"),
            'Generated TypeScript gitignored' => fn () => GitignoreEntry::ensure("{$root}/.gitignore", '/resources/js/'.TypeGeneration::OUTPUT_DIRECTORY),
            'Recorded Contracts gitignored' => fn () => GitignoreEntry::ensure("{$root}/.gitignore", '/resources/js/'.ContractRecorder::DIRECTORY),
            'package.json scripts: build, check and types:check generate first' => fn () => GenerateFirst::ensure("{$root}/package.json"),
            'Wayfinder QUERY verb workaround' => fn () => WayfinderQueryVerb::patch("{$root}/resources/js/wayfinder/index.ts"),
        ];
    }

    /** Apply every hook and Kit Conversion to the app at $root. */
    private function apply(string $root, Filesystem $files): void
    {
        foreach ($this->hooks($root, $files) as $hook => $apply) {
            $this->applyHook($hook, $apply);
        }

        $this->convertKit($root);
        // After Kit Conversion, which writes the include into a phpstan.neon it converts.
        $this->applyHook('PHPStan extension included in phpstan.neon', fn () => $this->includePhpstanExtension("{$root}/phpstan.neon"));
        $this->applyHook('phpstan.neon: no Strictly Laravel rule ignored', fn () => PhpstanIgnores::reject("{$root}/phpstan.neon"));
        // After Kit Conversion too, which writes the hook into a tests/Pest.php it converts: applied before it, the hook
        // would make the Base Kit's tests/Pest.php unknown content.
        $this->applyHook('tests/Pest.php: feature tests record Contracts', fn () => PestContractsHook::ensure("{$root}/tests/Pest.php"));
    }

    /**
     * Report one hook as a row: applied (checking: missing), unchanged, or not applied with its error.
     *
     * @param  callable(): bool  $apply  returns whether it changed anything
     */
    private function applyHook(string $hook, callable $apply): void
    {
        try {
            $changed = $apply();
        } catch (Throwable $e) {
            $this->components->twoColumnDetail($hook, '<fg=red>not applied</>');
            $this->components->error($e->getMessage());
            $this->incomplete[] = "{$hook} (not applied)";

            return;
        }

        $this->components->twoColumnDetail($hook, match (true) {
            ! $changed => '<fg=gray>unchanged</>',
            $this->checking => '<fg=yellow>missing</>',
            default => '<fg=green>applied</>',
        });

        if ($changed && $this->checking) {
            $this->incomplete[] = "{$hook} (missing)";
        }
    }

    /** A warning about what the installer just did. When checking it did nothing, and the hook's row says what's missing. */
    private function warnAboutChange(string $message): void
    {
        if (! $this->checking) {
            $this->components->warn($message);
        }
    }

    private function includePhpstanExtension(string $phpstanNeon): bool
    {
        $removed = PhpstanInclude::ensure($phpstanNeon);

        if ($removed === null) {
            return false;
        }

        $this->warnAboutChange('phpstan.neon was not converted, so only the Strictly Laravel extension was added to its includes'
            .($removed === [] ? '' : ', replacing the ones it already brings ('.implode(', ', $removed).')')
            .'. Check by hand that it sets no level below max and that its paths include tests/.');

        return true;
    }

    /**
     * One row per Base Kit file, labelled with its path. A skipped file doesn't fail the command (the app may have
     * edited it on purpose), but the row is reported so the fresh-install harness can fail on it (ADR-0005). Checking,
     * a file that still holds a known upstream version fails: a newer release converts it.
     */
    private function convertKit(string $root): void
    {
        try {
            $outcomes = KitConversion::apply($root);
        } catch (Throwable $e) {
            $this->components->twoColumnDetail('Kit Conversion', '<fg=red>not applied</>');
            $this->components->error($e->getMessage());
            $this->incomplete[] = 'Kit Conversion (not applied)';

            return;
        }

        $skipped = [];

        foreach ($outcomes as $path => $outcome) {
            $status = match ($outcome) {
                ConversionOutcome::Converted => $this->checking ? '<fg=yellow>not converted</>' : '<fg=green>applied</>',
                ConversionOutcome::AlreadyConverted => '<fg=gray>unchanged</>',
                ConversionOutcome::Missing => '<fg=yellow>not found</>',
                ConversionOutcome::Unknown => '<fg=yellow>skipped (unknown content)</>',
            };

            if ($outcome === ConversionOutcome::Converted && $this->checking) {
                $this->incomplete[] = "{$path} (not converted)";
            }

            if ($outcome === ConversionOutcome::Unknown) {
                $adds = KitConversion::addedBy($path);
                $skipped[] = "{$path} (sha256 ".KitConversion::hash("{$root}/{$path}").')'
                    .($adds === [] ? '' : ' and the files its conversion adds ('.implode(', ', $adds).')');
            }

            $this->components->twoColumnDetail($path, $status);
        }

        if ($skipped !== []) {
            $this->warnAboutChange('Kit Conversion left these files untouched because their content is not a known Base Kit version: '
                .implode('; ', $skipped).'. Compare each with its converted version in '
                .'vendor/mrsafalpiya/strictly-laravel/kit/ and apply the changes by hand.');
        }
    }

    private function writeCiCheck(string $composerJson, PackageManager $packageManager): bool
    {
        $result = CiCheckScripts::write($composerJson, $packageManager);

        if ($result['replaced'] !== []) {
            $this->warnAboutChange('Replaced or removed these edited ci:check steps: '.implode(', ', $result['replaced']).'. Add custom checks to a script of your own instead.');
        }

        return $result['changed'];
    }

    /** Write a file the app owns from then on, unless it exists: the installer never overwrites it. */
    private function publish(Filesystem $files, string $stub, string $target): bool
    {
        if ($files->exists($target)) {
            return false;
        }

        $files->ensureDirectoryExists(dirname($target));
        $files->copy($stub, $target);

        return true;
    }
}
