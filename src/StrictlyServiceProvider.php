<?php

namespace StrictlyLaravel;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use StrictlyLaravel\Console\InstallCommand;
use StrictlyLaravel\Workarounds\WayfinderQueryVerb;

final class StrictlyServiceProvider extends ServiceProvider
{
    public const string CONFIG_STUB = __DIR__.'/../config/strictly.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_STUB, 'strictly');
    }

    public function boot(): void
    {
        // Problem P-039: lazy loading, reading an attribute the query didn't select and filling a non-fillable
        // attribute throw instead of silently changing a prop's shape (or costing a query per row).
        Model::shouldBeStrict();

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([self::CONFIG_STUB => config_path('strictly.php')], 'strictly-config');

        $this->commands([InstallCommand::class]);

        Event::listen(CommandFinished::class, WayfinderQueryVerb::afterCommand(...));
    }
}
