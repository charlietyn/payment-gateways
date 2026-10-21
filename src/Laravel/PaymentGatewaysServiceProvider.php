<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Laravel;

use Illuminate\Support\ServiceProvider;
use Ronu\PaymentGateways\Contracts\Gateway;

/**
 * Optional Laravel bridge. The core never depends on Laravel; this only binds
 * the manager and the default gateway, and publishes the config.
 */
final class PaymentGatewaysServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/payment-gateways.php', 'payment-gateways');

        $this->app->singleton(PaymentGatewaysManager::class, fn ($app) => new PaymentGatewaysManager((array) $app['config']->get('payment-gateways', [])));
        $this->app->bind(Gateway::class, fn ($app) => $app->make(PaymentGatewaysManager::class)->gateway());
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../../config/payment-gateways.php' => $this->app->configPath('payment-gateways.php')], 'payment-gateways-config');
        }
    }
}
