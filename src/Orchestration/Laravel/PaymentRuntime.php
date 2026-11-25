<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration\Laravel;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use GuzzleHttp\ClientInterface;
use Psr\Log\{LoggerInterface, NullLogger};
use Ronu\PaymentGateways\Laravel\PaymentGatewaysServiceProvider;
use Ronu\PaymentGateways\Orchestration\Contracts\FailureReporter;
use Ronu\PaymentGateways\Services;
use Ronu\PaymentGateways\Orchestration\{Coordinator, DisputeCoordinator, GatewayDisputeEvidenceReader, GatewayRefundEvidenceReader, PaymentError, PostgresStore, RecoveryWorker, RefundCoordinator};

/** Lazy wiring: discovery, route listing and config cache do not connect or call providers. */
final class PaymentRuntime
{
    private ?PostgresStore $storage = null;
    /** @param array<string, mixed> $configuration */
    public function __construct(private Container $container, private array $configuration)
    {
    }

    public function adapter(): ProductAdapter
    {
        $class = $this->configuration['adapter'] ?? null;
        if (!is_string($class) || !is_subclass_of($class, ProductAdapter::class)) {
            throw new PaymentError('adapter_missing', 503);
        }
        $adapter = $this->container->make($class);
        return $adapter;
    }

    public function store(): PostgresStore
    {
        if ($this->storage !== null) {
            return $this->storage;
        }
        $manager = $this->container->make(DatabaseManager::class);
        $connection = $manager->connection($this->configuration['connection']);
        if ($connection->getDriverName() !== 'pgsql') {
            throw new PaymentError('unsupported_storage', 503);
        }
        return $this->storage = new PostgresStore($connection->getPdo(), $this->configuration['tables']);
    }

    public function accounts(): ConfiguredAccounts
    {
        return new ConfiguredAccounts($this->configuration, $this->services());
    }

    /** Log line and event for the failures the orchestration answers instead of throwing. */
    public function failures(): FailureReporter
    {
        $events = $this->container->bound('events') ? $this->container->make('events') : null;
        return new FailureReporting($this->logger(), $events instanceof Dispatcher ? $events : null);
    }

    /** Same HTTP client binding and log channel as the gateway facade: one line per provider call. */
    private function services(): Services
    {
        $http = $this->container->bound(PaymentGatewaysServiceProvider::HTTP) ? $this->container->make(PaymentGatewaysServiceProvider::HTTP) : null;
        return new Services(http: $http instanceof ClientInterface ? $http : null, logger: $this->logger());
    }

    private function logger(): LoggerInterface
    {
        if (!$this->container->bound('log')) {
            return new NullLogger();
        }
        $log = $this->container->make('log');
        $channel = $this->container->bound('config') ? $this->container->make('config')->get('payment-gateways.log_channel') : null;
        $logger = is_object($log) && is_string($channel) && $channel !== '' && method_exists($log, 'channel') ? $log->channel($channel) : $log;
        return $logger instanceof LoggerInterface ? $logger : new NullLogger();
    }
    public function coordinator(): Coordinator
    {
        $subjects = new class ($this->adapter()) implements \Ronu\PaymentGateways\Orchestration\Contracts\SubjectResolver {
            public function __construct(private ProductAdapter $adapter)
            {
            }
            public function resolve(\Ronu\PaymentGateways\Orchestration\Actor $actor, string $subject): \Ronu\PaymentGateways\Orchestration\Quote
            {
                if (!$this->adapter->ready()) {
                    throw new PaymentError('adapter_not_ready', 503);
                }
                return $this->adapter->resolve($actor, $subject);
            }
        };
        return new Coordinator($this->store(), $subjects, $this->accounts(), fn () => time(), $this->failures());
    }
    public function disputes(): DisputeCoordinator
    {
        return new DisputeCoordinator($this->store(), $this->accounts(), new GatewayDisputeEvidenceReader());
    }
    public function externalRefunds(): \Ronu\PaymentGateways\Orchestration\ExternalRefundCoordinator
    {
        return new \Ronu\PaymentGateways\Orchestration\ExternalRefundCoordinator($this->store(), $this->accounts(), new GatewayRefundEvidenceReader());
    }
    public function refunds(): RefundCoordinator
    {
        // New refund writes need an explicit consumer decision; reads and recovery never do.
        return new RefundCoordinator($this->store(), $this->accounts(), new GatewayRefundEvidenceReader(), ($this->configuration['refunds_enabled'] ?? false) === true, $this->failures());
    }
    /** Back office (0.10.0): fails closed when the project did not configure who its operators are. */
    public function operators(): OperatorAdapter
    {
        $class = $this->configuration['admin']['operator'] ?? null;
        if (!is_string($class) || !is_subclass_of($class, OperatorAdapter::class)) {
            throw new PaymentError('admin_unavailable', 503);
        }
        return $this->container->make($class);
    }

    public function console(): \Ronu\PaymentGateways\Orchestration\OperatorConsole
    {
        return new \Ronu\PaymentGateways\Orchestration\OperatorConsole($this->store(), $this->accounts(), ($this->configuration['refunds_enabled'] ?? false) === true, ($this->configuration['recovery_enabled'] ?? false) === true);
    }

    /** Dispatches after the work committed; a listener failure never changes the answer. */
    public function recordOperatorAction(Events\OperatorActionRecorded $event): void
    {
        try {
            $events = $this->container->bound('events') ? $this->container->make('events') : null;
            if ($events instanceof Dispatcher) {
                $events->dispatch($event);
            }
        } catch (\Throwable) {
        }
    }

    public function compensations(): \Ronu\PaymentGateways\Orchestration\CompensationCoordinator
    {
        $adapter = $this->adapter();
        if (!$adapter instanceof \Ronu\PaymentGateways\Orchestration\Contracts\ManualCompensation) {
            throw new PaymentError('manual_compensation_unsupported', 503);
        }
        return new \Ronu\PaymentGateways\Orchestration\CompensationCoordinator($this->store(), $adapter);
    }
    public function worker(): RecoveryWorker
    {
        if (!($this->configuration['recovery_enabled'] ?? false)) {
            throw new PaymentError('recovery_disabled', 503);
        }
        $adapter = $this->adapter();
        return new RecoveryWorker(
            $this->store(),
            $this->coordinator(),
            $this->accounts(),
            $adapter,
            $adapter,
            new RefundCoordinator($this->store(), $this->accounts(), new GatewayRefundEvidenceReader(), false),
            $this->disputes()
        );
    }
}
