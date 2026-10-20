<?php

namespace StrictlyLaravel;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Spatie\LaravelData\Resolvers\DataValidationRulesResolver;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfig;
use StrictlyLaravel\Boost\BoostGuidance;
use StrictlyLaravel\Console\CoverageCommand;
use StrictlyLaravel\Console\GenerateCommand;
use StrictlyLaravel\Console\InstallCommand;
use StrictlyLaravel\Console\MakePageCommand;
use StrictlyLaravel\Data\DateTimeCast;
use StrictlyLaravel\Routes\WayfinderTemplates;
use StrictlyLaravel\TypeScript\TypeGeneration;
use StrictlyLaravel\Workarounds\LaravelData\RulesWithRouteModels;
use StrictlyLaravel\Workarounds\LaravelData\StrictInputConfig;
use StrictlyLaravel\Workarounds\WayfinderQueryVerb;

final class StrictlyServiceProvider extends ServiceProvider
{
    /** The package's defaults, merged under the app's config/strictly.php. */
    public const string CONFIG_DEFAULTS = __DIR__.'/../config/strictly.php';

    /** What `strictly:install` publishes as the app's config/strictly.php: the Base Kit's vendor declarations. */
    public const string CONFIG_STUB = __DIR__.'/../stubs/strictly.php';

    /** What `strictly:install` publishes as the app's tests/Feature/LocaleParityTest.php (Problem P-057). */
    public const string LOCALE_PARITY_TEST_STUB = __DIR__.'/../stubs/LocaleParityTest.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_DEFAULTS, 'strictly');

        // Also what `typescript:transform` (and its --watch) runs.
        $this->app->singleton(TypeScriptTransformerConfig::class, TypeGeneration::config(...));

        // Before any provider boots, so laravel-data reads the config with the Overlay's date cast (Problem P-173) and
        // the workarounds applied.
        $this->app->booting(function (): void {
            $config = $this->app->make('config');
            DateTimeCast::useAsGlobalCast($config);
            StrictInputConfig::apply($config);
        });

        // Problem P-126: an input's rules() receive the route's bound models.
        $this->app->bind(DataValidationRulesResolver::class, RulesWithRouteModels::class);
    }

    public function boot(): void
    {
        // Problem P-039: lazy loading, reading an attribute the query didn't select and filling a non-fillable
        // attribute throw instead of silently changing a prop's shape (or costing a query per row). Off in
        // production, where the tests have already caught them, so a missed case is not a 500.
        Model::shouldBeStrict(! $this->app->isProduction());

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([CoverageCommand::class, GenerateCommand::class, InstallCommand::class, MakePageCommand::class]);

        Event::listen(CommandFinished::class, WayfinderQueryVerb::afterCommand(...));

        // Laravel Boost installs the package's guideline and skills (Problem P-146).
        Event::listen(CommandStarting::class, (new BoostGuidance($this->app->basePath('boost.json')))->beforeCommand(...));

        // Wayfinder generates routes from the console only (its command and its Vite plugin's).
        $this->callAfterResolving('view', WayfinderTemplates::register(...));
    }
}
