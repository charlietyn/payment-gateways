<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration\Laravel;

use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentRuntime::class, fn ($app) => new PaymentRuntime($app, $app['config']->get('payments', [])));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PaymentCommand::class]);
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                if ($this->app['config']->get('payments.recovery_enabled', false)) {
                    $schedule->command('payments:run work --limit=200')->everyMinute();
                }
            });
        }
    }
}
