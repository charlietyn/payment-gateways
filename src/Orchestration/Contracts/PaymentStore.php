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
    /** @return array<string, mixed>|null */
    public function operationByKey(string $scope, string $key, ?string $alias): ?array;
    /** @return array<string, mixed>|null */
    public function intentBySubject(string $scope, string $subject): ?array;
    /** @return array<string, mixed>|null */
    public function intent(string $reference, bool $lock = false): ?array;
    /** @return array<string, mixed>|null */
    public function operation(string $reference): ?array;
    /** @param array<string, mixed> $intent */
    public function insertIntent(array $intent): void;
    /** @param array<string, mixed> $operation */
    public function insertOperation(array $operation): void;
    /** @param array<string, mixed> $intent */
    public function saveIntent(array $intent, int $expectedVersion): bool;
    /** @param array<string, mixed> $operation */
    public function saveOperation(array $operation): void;
    /** @param array<string, mixed> $intent */
    public function recordCapture(array $intent, string $remoteTransaction): void;
    /** @param array<string, mixed> $payload */
    public function recordEvent(string $eventId, string $type, array $payload): void;
}
