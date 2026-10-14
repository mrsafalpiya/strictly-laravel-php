<?php

namespace StrictlyLaravel;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfig;
use StrictlyLaravel\Console\GenerateCommand;
use StrictlyLaravel\Console\InstallCommand;
use StrictlyLaravel\Console\MakePageCommand;
use StrictlyLaravel\TypeScript\TypeGeneration;
use StrictlyLaravel\Workarounds\WayfinderQueryVerb;

final class StrictlyServiceProvider extends ServiceProvider
{
    public const string CONFIG_STUB = __DIR__.'/../config/strictly.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_STUB, 'strictly');

        // Also what `typescript:transform` (and its --watch) runs.
        $this->app->singleton(TypeScriptTransformerConfig::class, TypeGeneration::config(...));
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

        $this->publishes([self::CONFIG_STUB => config_path('strictly.php')], 'strictly-config');

        $this->commands([GenerateCommand::class, InstallCommand::class, MakePageCommand::class]);

        Event::listen(CommandFinished::class, WayfinderQueryVerb::afterCommand(...));
    }
}
