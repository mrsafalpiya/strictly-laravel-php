<?php

namespace StrictlyLaravel;

use Illuminate\Console\Events\CommandFinished;
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
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([self::CONFIG_STUB => config_path('strictly.php')], 'strictly-config');

        $this->commands([InstallCommand::class]);

        Event::listen(CommandFinished::class, WayfinderQueryVerb::afterCommand(...));
    }
}
