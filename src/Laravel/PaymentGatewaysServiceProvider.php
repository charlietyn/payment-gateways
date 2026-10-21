<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Laravel;

use Illuminate\Support\ServiceProvider;
use Psr\SimpleCache\CacheInterface;
use Ronu\PaymentGateways\Contracts\Gateway;
use Ronu\PaymentGateways\Services;

/**
 * Optional Laravel bridge. The core never depends on Laravel; this only binds
 * the manager and the default gateway, and publishes the config.
 */
final class PaymentGatewaysServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/payment-gateways.php', 'payment-gateways');

        $this->app->singleton(PaymentGatewaysManager::class, fn ($app) => new PaymentGatewaysManager(
            (array) $app['config']->get('payment-gateways', []),
            new Services(cache: $this->cache()),
        ));
        $this->app->bind(Gateway::class, fn ($app) => $app->make(PaymentGatewaysManager::class)->gateway());
    }

    /** The default cache store (a PSR-16 repository), when the app has one. */
    private function cache(): ?CacheInterface
    {
        if (! $this->app->bound('cache')) {
            return null;
        }
        $store = $this->app->make('cache')->store();

        return $store instanceof CacheInterface ? $store : null;
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../../config/payment-gateways.php' => $this->app->configPath('payment-gateways.php')], 'payment-gateways-config');
        }
    }
}
