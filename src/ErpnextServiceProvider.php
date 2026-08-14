<?php

namespace Kayedspace\Erpnext;

use Illuminate\Support\ServiceProvider;
use Kayedspace\Erpnext\Client\ErpClient;

class ErpnextServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/erpnext.php', 'erpnext');

        /*
         * Bound from config by default. An application with its own idea of where
         * credentials live — per-tenant settings, say — rebinds this with a resolver
         * of its own; the closure is re-invoked per request, so credentials stay
         * correct even on a long-lived instance.
         */
        $this->app->singleton(ErpClient::class, fn ($app): ErpClient => new ErpClient(
            fn (): Connection => Connection::fromArray($app['config']->get('erpnext', [])),
            $app['config']->get('erpnext.naming_fields', []),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/erpnext.php' => config_path('erpnext.php'),
            ], 'erpnext-config');
        }
    }
}
