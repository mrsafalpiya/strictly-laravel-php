<?php

namespace StrictlyLaravel\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use StrictlyLaravel\Install\CiCheckScripts;
use StrictlyLaravel\Install\PackageManager;
use StrictlyLaravel\StrictlyServiceProvider;
use StrictlyLaravel\Workarounds\WayfinderQueryVerb;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Applies the Overlay's hooks to the app. Idempotent and non-interactive: running it again changes nothing, and it
 * never overwrites a file the app owns (such as an existing config/strictly.php).
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
            'Wayfinder QUERY verb workaround' => fn () => WayfinderQueryVerb::patch(resource_path('js/wayfinder/index.ts')),
        ];

        $failed = false;

        foreach ($hooks as $hook => $apply) {
            try {
                $this->components->twoColumnDetail($hook, $apply() ? '<fg=green>applied</>' : '<fg=gray>unchanged</>');
            } catch (Throwable $e) {
                $failed = true;
                $this->components->twoColumnDetail($hook, '<fg=red>not applied</>');
                $this->components->error($e->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
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
