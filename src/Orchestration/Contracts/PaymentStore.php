<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration\Contracts;

interface PaymentStore
{
    /** Same connection for the aggregate, operation journal, ledger and outbox. */
    public function transaction(callable $work): mixed;
    public function assertProviderIoAllowed(): void;
    /** Database transaction lock, never held around provider I/O. */
    public function lock(string $scope, string $subject): void;
    public function operationByKey(string $scope, string $key, ?string $alias): ?array;
    public function intentBySubject(string $scope, string $subject): ?array;
    public function intent(string $reference, bool $lock = false): ?array;
    public function operation(string $reference): ?array;
    public function insertIntent(array $intent): void;
    public function insertOperation(array $operation): void;
    public function saveIntent(array $intent, int $expectedVersion): bool;
    public function saveOperation(array $operation): void;
    public function recordCapture(array $intent, string $remoteTransaction): void;
    public function recordEvent(string $eventId, string $type, array $payload): void;
}
