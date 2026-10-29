<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration\Laravel;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseManager;
use Ronu\PaymentGateways\Orchestration\{Coordinator, DisputeCoordinator, GatewayDisputeEvidenceReader, GatewayRefundEvidenceReader, PaymentError, PostgresStore, RecoveryWorker, RefundCoordinator};

/** Lazy wiring: discovery, route listing and config cache do not connect or call providers. */
final class PaymentRuntime
{
    private ?PostgresStore $storage = null;
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
        return new ConfiguredAccounts($this->configuration);
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
        return new Coordinator($this->store(), $subjects, $this->accounts(), fn () => time());
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
        return new RefundCoordinator($this->store(), $this->accounts(), new GatewayRefundEvidenceReader(), false);
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
