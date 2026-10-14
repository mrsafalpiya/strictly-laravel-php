<?php

namespace StrictlyLaravel\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use StrictlyLaravel\Install\CiCheckScripts;
use StrictlyLaravel\Install\ConversionOutcome;
use StrictlyLaravel\Install\GenerateFirst;
use StrictlyLaravel\Install\GitignoreEntry;
use StrictlyLaravel\Install\KitConversion;
use StrictlyLaravel\Install\PackageManager;
use StrictlyLaravel\Install\PhpstanInclude;
use StrictlyLaravel\Install\TsconfigFlags;
use StrictlyLaravel\Install\ViteLintPlugin;
use StrictlyLaravel\Install\VitePluginEntry;
use StrictlyLaravel\StrictlyServiceProvider;
use StrictlyLaravel\TypeScript\TypeGeneration;
use StrictlyLaravel\Workarounds\WayfinderQueryVerb;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Applies the Overlay's hooks and Kit Conversion to the app. Idempotent and non-interactive: running it again changes
 * nothing, and it never overwrites a file the app owns (such as an existing config/strictly.php, or a Base Kit file
 * whose content Kit Conversion doesn't recognise).
 */
#[AsCommand(name: 'strictly:install')]
final class InstallCommand extends Command
{
    protected $signature = 'strictly:install';

    protected $description = 'Apply the Strictly Laravel Overlay to this app (safe to run again)';

    public function handle(Filesystem $files): int
    {
        $packageManager = PackageManager::detect($this->laravel->basePath());

        $hooks = [
            'config/strictly.php' => fn () => $this->publishConfig($files),
            "Composer scripts: ci:check and its steps (using {$packageManager->value})" => fn () => $this->writeCiCheck($packageManager),
            'tsconfig.json: strictness flags' => fn () => TsconfigFlags::patch($this->laravel->basePath('tsconfig.json')),
            'vite.config.ts: Strictly Laravel oxlint plugin' => fn () => ViteLintPlugin::register($this->laravel->basePath('vite.config.ts')),
            'vite.config.ts: Strictly Laravel Vite plugin' => fn () => VitePluginEntry::register($this->laravel->basePath('vite.config.ts')),
            'Generated TypeScript gitignored' => fn () => GitignoreEntry::ensure(
                $this->laravel->basePath('.gitignore'),
                '/resources/js/'.TypeGeneration::OUTPUT_DIRECTORY,
            ),
            'package.json scripts: build and types:check generate first' => fn () => GenerateFirst::ensure($this->laravel->basePath('package.json')),
            'Wayfinder QUERY verb workaround' => fn () => WayfinderQueryVerb::patch(resource_path('js/wayfinder/index.ts')),
        ];

        $failed = false;

        foreach ($hooks as $hook => $apply) {
            $failed = ! $this->applyHook($hook, $apply) || $failed;
        }

        $failed = ! $this->convertKit() || $failed;
        // After Kit Conversion, which writes the include into a phpstan.neon it converts.
        $failed = ! $this->applyHook('PHPStan extension included in phpstan.neon', $this->includePhpstanExtension(...)) || $failed;

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Report one hook as a row: applied, unchanged, or not applied with its error. Returns false when it failed.
     *
     * @param  callable(): bool  $apply  returns whether it changed anything
     */
    private function applyHook(string $hook, callable $apply): bool
    {
        try {
            $this->components->twoColumnDetail($hook, $apply() ? '<fg=green>applied</>' : '<fg=gray>unchanged</>');

            return true;
        } catch (Throwable $e) {
            $this->components->twoColumnDetail($hook, '<fg=red>not applied</>');
            $this->components->error($e->getMessage());

            return false;
        }
    }

    private function includePhpstanExtension(): bool
    {
        $removed = PhpstanInclude::ensure($this->laravel->basePath('phpstan.neon'));

        if ($removed === null) {
            return false;
        }

        $this->components->warn('phpstan.neon was not converted, so only the Strictly Laravel extension was added to its includes'
            .($removed === [] ? '' : ', replacing the ones it already brings ('.implode(', ', $removed).')')
            .'. Check by hand that it sets no level below max and that its paths include tests/.');

        return true;
    }

    /**
     * One row per Base Kit file, labelled with its path. A skipped file doesn't fail the command (the app may have
     * edited it on purpose), but the row is reported so the fresh-install harness can fail on it (ADR-0005).
     */
    private function convertKit(): bool
    {
        try {
            $outcomes = KitConversion::apply($this->laravel->basePath());
        } catch (Throwable $e) {
            $this->components->twoColumnDetail('Kit Conversion', '<fg=red>not applied</>');
            $this->components->error($e->getMessage());

            return false;
        }

        $skipped = [];

        foreach ($outcomes as $path => $outcome) {
            $status = match ($outcome) {
                ConversionOutcome::Converted => '<fg=green>applied</>',
                ConversionOutcome::AlreadyConverted => '<fg=gray>unchanged</>',
                ConversionOutcome::Missing => '<fg=yellow>not found</>',
                ConversionOutcome::Unknown => '<fg=yellow>skipped (unknown content)</>',
            };

            if ($outcome === ConversionOutcome::Unknown) {
                $skipped[] = "{$path} (sha256 ".KitConversion::hash($this->laravel->basePath($path)).')';
            }

            $this->components->twoColumnDetail($path, $status);
        }

        if ($skipped !== []) {
            $this->components->warn('Kit Conversion left these files untouched because their content is not a known Base Kit version: '
                .implode(', ', $skipped).'. Compare each with its converted version in '
                .'vendor/mrsafalpiya/strictly-laravel/kit/ and apply the changes by hand.');
        }

        return true;
    }

    private function writeCiCheck(PackageManager $packageManager): bool
    {
        $result = CiCheckScripts::write($this->laravel->basePath('composer.json'), $packageManager);

        if ($result['replaced'] !== []) {
            $this->components->warn('Replaced or removed these edited ci:check steps: '.implode(', ', $result['replaced']).'. Add custom checks to a script of your own instead.');
        }

        return $result['changed'];
    }

    private function publishConfig(Filesystem $files): bool
    {
        $target = config_path('strictly.php');

        if ($files->exists($target)) {
            return false;
        }

        $files->copy(StrictlyServiceProvider::CONFIG_STUB, $target);

        return true;
    }
}
