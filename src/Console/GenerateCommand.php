<?php

namespace StrictlyLaravel\Console;

use Illuminate\Console\Command;
use Spatie\LaravelTypeScriptTransformer\Support\LaravelConsoleLogger;
use Spatie\TypeScriptTransformer\Enums\RunnerMode;
use Spatie\TypeScriptTransformer\Runners\Runner;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfig;
use StrictlyLaravel\TypeScript\RawInertiaPropException;
use StrictlyLaravel\Workarounds\TypeScriptTransformer\UnmappedClassException;
use StrictlyLaravel\Workarounds\UnparsableFiles;
use StrictlyLaravel\Workarounds\WayfinderQueryVerb;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Generates every TypeScript file the app's React code imports from PHP: the Data and enum types (`@/generated`) and
 * Wayfinder's routes and actions. All of it is gitignored, so build, typecheck and `ci:check` run this first. The npm
 * package's Vite plugin runs it with `--skip-routes` whenever a PHP file under app/ changes, since the Base Kit's
 * Wayfinder plugin already regenerates the routes in dev.
 */
#[AsCommand(name: 'strictly:generate')]
final class GenerateCommand extends Command
{
    protected $signature = 'strictly:generate
        {--skip-routes : Generate only the Data and enum types, not Wayfinder\'s routes and actions}';

    protected $description = 'Generate the TypeScript types for Data classes, enums and routes';

    public function handle(Runner $runner, TypeScriptTransformerConfig $config): int
    {
        $unparsable = UnparsableFiles::in($config->directoriesToWatch, $this->laravel->basePath());

        if ($unparsable !== []) {
            $this->components->error('Cannot generate types while a PHP file has a syntax error:');

            foreach ($unparsable as $error) {
                $this->line("  {$error}");
            }

            return self::FAILURE;
        }

        try {
            $exitCode = $runner->run(new LaravelConsoleLogger($this), $config, RunnerMode::Direct);
        } catch (RawInertiaPropException|UnmappedClassException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($exitCode !== self::SUCCESS) {
            return $exitCode;
        }

        if (! $this->option('skip-routes') && $this->getApplication()?->has('wayfinder:generate') === true) {
            // The same options as the Base Kit's Wayfinder Vite plugin (`formVariants: true`).
            $exitCode = $this->call('wayfinder:generate', ['--with-form' => true]);
            // Called in-process, so no CommandFinished event triggers the workaround: apply it here.
            WayfinderQueryVerb::patch(resource_path('js/wayfinder/index.ts'));
        }

        return $exitCode;
    }
}
