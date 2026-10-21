<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Laravel;

use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Ronu\PaymentGateways\Contracts\EventDeduplicator;
use Ronu\PaymentGateways\Contracts\Gateway;
use Ronu\PaymentGateways\Services;
use Ronu\PaymentGateways\Webhooks\CacheDeduplicator;
use Ronu\PaymentGateways\Webhooks\InMemoryDeduplicator;

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
            new Services(cache: $this->cache(), logger: $this->logger($app['config']->get('payment-gateways.log_channel'))),
        ));
        $this->app->bind(Gateway::class, fn ($app) => $app->make(PaymentGatewaysManager::class)->gateway());
        $this->app->singleton(EventDeduplicator::class, function (): EventDeduplicator {
            $cache = $this->cache();

            return $cache !== null ? new CacheDeduplicator($cache) : new InMemoryDeduplicator();
        });
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

    /** The app logger, or one of its channels (payment-gateways.log_channel). */
    private function logger(mixed $channel): ?LoggerInterface
    {
        if (! $this->app->bound('log')) {
            return null;
        }
        $log = $this->app->make('log');
        $logger = is_object($log) && is_string($channel) && $channel !== '' && method_exists($log, 'channel') ? $log->channel($channel) : $log;

        return $logger instanceof LoggerInterface ? $logger : null;
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../../config/payment-gateways.php' => $this->app->configPath('payment-gateways.php')], 'payment-gateways-config');
        }
    }
}
