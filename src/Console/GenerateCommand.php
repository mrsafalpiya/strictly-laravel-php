<?php

namespace StrictlyLaravel\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Router;
use RuntimeException;
use Spatie\LaravelTypeScriptTransformer\Support\LaravelConsoleLogger;
use Spatie\TypeScriptTransformer\Enums\RunnerMode;
use Spatie\TypeScriptTransformer\Runners\Runner;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfig;
use StrictlyLaravel\Broadcasting\BroadcastChannels;
use StrictlyLaravel\Broadcasting\BroadcastEvents;
use StrictlyLaravel\Broadcasting\BroadcastingModule;
use StrictlyLaravel\Broadcasting\PublicChannels;
use StrictlyLaravel\Routes\RouteKeyFieldsModule;
use StrictlyLaravel\Routes\RouteKeys;
use StrictlyLaravel\Routes\VendorDeclarations;
use StrictlyLaravel\Translations\Translations;
use StrictlyLaravel\Translations\TranslationsModule;
use StrictlyLaravel\TypeScript\RawInertiaPropException;
use StrictlyLaravel\TypeScript\TypeGeneration;
use StrictlyLaravel\Workarounds\TypeScriptTransformer\UnmappedClassException;
use StrictlyLaravel\Workarounds\UnparsableFiles;
use StrictlyLaravel\Workarounds\WayfinderQueryVerb;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Generates every TypeScript file the app's React code imports from PHP: the Data and enum types (`@/generated`), the
 * translations and their typed `t()` (`@/generated/translations`, from lang/), the broadcast events and channels with
 * their typed Echo helpers (`@/generated/broadcasting`, from the events and routes/channels.php), the route key name of
 * each bound model (`@/generated/route-keys`, which `RouteKey<'Issue', number>` reads) and Wayfinder's routes
 * and actions. All of it is gitignored, so build, typecheck and `ci:check` run this first. The npm package's Vite plugin
 * runs it with `--skip-routes` whenever a PHP file under app/ or lang/, or routes/channels.php, changes, since the Base
 * Kit's Wayfinder plugin already regenerates the routes in dev.
 */
#[AsCommand(name: 'strictly:generate')]
final class GenerateCommand extends Command
{
    protected $signature = 'strictly:generate
        {--skip-routes : Generate only the Data and enum types, the translations and the broadcasts, not Wayfinder\'s routes and actions}';

    protected $description = 'Generate the TypeScript types for Data classes, enums, translations, broadcasts and routes';

    public function handle(Runner $runner, TypeScriptTransformerConfig $config, Filesystem $files): int
    {
        $unparsable = UnparsableFiles::in($config->directoriesToWatch, $this->laravel->basePath());

        if ($unparsable !== []) {
            $this->components->error('Cannot generate types while a PHP file has a syntax error:');

            foreach ($unparsable as $error) {
                $this->line("  {$error}");
            }

            return self::FAILURE;
        }

        $declarations = VendorDeclarations::check($this->laravel->make(Router::class));

        if ($declarations !== []) {
            $this->components->error('config/strictly.php declares what doesn\'t exist, so these vendor routes would be untyped (Problems P-025, P-050):');

            foreach ($declarations as $error) {
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

        try {
            TranslationsModule::write(Translations::ofApp(), TypeGeneration::outputDirectory(), $files);
        } catch (RuntimeException $exception) {
            $this->components->error('Cannot generate the translations: '.$exception->getMessage());

            return self::FAILURE;
        }

        $publicChannels = PublicChannels::check(BroadcastChannels::defined($this->laravel));

        if ($publicChannels !== []) {
            $this->components->error('config/strictly.php declares public channels that don\'t name one, so publicChannel() would take any name (Problem P-143):');

            foreach ($publicChannels as $error) {
                $this->line("  {$error}");
            }

            return self::FAILURE;
        }

        try {
            $events = BroadcastEvents::in([$this->laravel->path()]);
        } catch (RuntimeException $exception) {
            $this->components->error('Cannot generate the broadcast events: '.$exception->getMessage());

            return self::FAILURE;
        }

        $channels = BroadcastChannels::of($this->laravel);
        $undeclared = BroadcastEvents::check($events, $channels);

        if ($undeclared !== []) {
            $this->components->error('A broadcast event declares a channel the app doesn\'t know, so no listener could name it (Problem P-144):');

            foreach ($undeclared as $error) {
                $this->line("  {$error}");
            }

            return self::FAILURE;
        }

        BroadcastingModule::write($events, $channels, TypeGeneration::outputDirectory(), $files);

        RouteKeyFieldsModule::write(RouteKeys::of($this->laravel->make(Router::class))->routeKeyFields(), TypeGeneration::outputDirectory(), $files);

        if (! $this->option('skip-routes')) {
            $untyped = RouteKeys::untypedBindings($this->laravel->make(Router::class));

            if ($untyped !== []) {
                $this->components->error('A route parameter names a binding field, but its action doesn\'t type-hint the model, so its route function takes any value instead of that model\'s key (Problem P-002). Type-hint the bound model in the action, such as show(Workspace $workspace):');

                foreach ($untyped as $binding) {
                    $this->line("  {$binding}");
                }

                return self::FAILURE;
            }
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
