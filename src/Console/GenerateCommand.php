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
use StrictlyLaravel\Workarounds\TypeScriptTransformer\DuplicateTypeNamesException;
use StrictlyLaravel\Workarounds\TypeScriptTransformer\UnloadableClasses;
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
            return $this->failWith('Cannot generate types while a PHP file has a syntax error:', $unparsable);
        }

        $unloadable = UnloadableClasses::in([$this->laravel->path()], $this->laravel->basePath());

        if ($unloadable !== []) {
            return $this->failWith('Cannot generate types while a class that gets one can\'t be loaded, so its type would be missing (Problem P-073):', $unloadable);
        }

        $declarations = VendorDeclarations::check($this->laravel->make(Router::class));

        if ($declarations !== []) {
            return $this->failWith('config/strictly.php declares what doesn\'t exist, so these vendor routes would be untyped (Problems P-025, P-050):', $declarations);
        }

        try {
            $exitCode = $runner->run(new LaravelConsoleLogger($this), $config, RunnerMode::Direct);
        } catch (RawInertiaPropException|UnmappedClassException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } catch (DuplicateTypeNamesException $exception) {
            return $this->failWith('PHP classes generate the same TypeScript type name, which @/generated can declare only once (Problem P-063):', $exception->clashes);
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
            return $this->failWith('config/strictly.php declares public channels that don\'t name one, so publicChannel() would take any name (Problem P-143):', $publicChannels);
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
            return $this->failWith('A broadcast event declares a channel the app doesn\'t know, so no listener could name it (Problem P-144):', $undeclared);
        }

        BroadcastingModule::write($events, $channels, TypeGeneration::outputDirectory(), $files);

        RouteKeyFieldsModule::write(RouteKeys::of($this->laravel->make(Router::class))->routeKeyFields(), TypeGeneration::outputDirectory(), $files);

        if (! $this->option('skip-routes')) {
            $untyped = RouteKeys::untypedBindings($this->laravel->make(Router::class));

            if ($untyped !== []) {
                return $this->failWith('A route parameter names a binding field, but its action doesn\'t type-hint the model, so its route function takes any value instead of that model\'s key (Problem P-002). Type-hint the bound model in the action, such as show(Workspace $workspace):', $untyped);
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

    /**
     * Fails generation with a headline and one indented line per problem it found. Every check that can find more than
     * one problem at once reports them this way, so the Vite plugin's log shows them all.
     *
     * @param  list<string>  $problems
     */
    private function failWith(string $headline, array $problems): int
    {
        $this->components->error($headline);

        foreach ($problems as $problem) {
            $this->line("  {$problem}");
        }

        return self::FAILURE;
    }
}
