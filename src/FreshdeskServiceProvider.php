<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorFreshdesk;

use Illuminate\Support\ServiceProvider;

final class FreshdeskServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/connector-freshdesk.php', 'connector-freshdesk');
        // Also supports Composer local overrides: connector-base reads the host's
        // normal lockfile, while Laravel discovers providers from installed metadata.
        $builtIn = (array) config('connectors.built_in', []);
        $builtIn[] = FreshdeskConnector::class;
        config(['connectors.built_in' => array_values(array_unique($builtIn))]);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/connector-freshdesk.php' => config_path('connector-freshdesk.php')], 'connector-freshdesk-config');
            $this->publishes([__DIR__.'/../public/icons/freshdesk.svg' => public_path('connectors/freshdesk.svg')], 'connector-freshdesk-assets');
        }
    }
}
