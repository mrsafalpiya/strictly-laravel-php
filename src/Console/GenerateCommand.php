<?php

namespace StrictlyLaravel\Console;

use Illuminate\Console\Command;
use Spatie\LaravelTypeScriptTransformer\Support\LaravelConsoleLogger;
use Spatie\TypeScriptTransformer\Enums\RunnerMode;
use Spatie\TypeScriptTransformer\Runners\Runner;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfig;
use StrictlyLaravel\Workarounds\WayfinderQueryVerb;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Generates every TypeScript file the app's React code imports from PHP: the Data and enum types (`@/generated`) and
 * Wayfinder's routes and actions. All of it is gitignored, so build, typecheck and `ci:check` run this first.
 */
#[AsCommand(name: 'strictly:generate')]
final class GenerateCommand extends Command
{
    protected $signature = 'strictly:generate';

    protected $description = 'Generate the TypeScript types for Data classes, enums and routes';

    public function handle(Runner $runner, TypeScriptTransformerConfig $config): int
    {
        $exitCode = $runner->run(new LaravelConsoleLogger($this), $config, RunnerMode::Direct);

        if ($exitCode !== self::SUCCESS) {
            return $exitCode;
        }

        if ($this->getApplication()?->has('wayfinder:generate') === true) {
            // The same options as the Base Kit's Wayfinder Vite plugin (`formVariants: true`).
            $exitCode = $this->call('wayfinder:generate', ['--with-form' => true]);
            // Called in-process, so no CommandFinished event triggers the workaround: apply it here.
            WayfinderQueryVerb::patch(resource_path('js/wayfinder/index.ts'));
        }

        return $exitCode;
    }
}
