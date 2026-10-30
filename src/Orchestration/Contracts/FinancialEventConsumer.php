<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration\Contracts;

interface FinancialEventConsumer
{
    /**
     * Persist effect and eventId deduplication together. Projections must reject older observationVersion; delivery can be out of order. Never infer a second refund command.
     *
     * @param array<string, mixed> $payload
     */
    public function consume(string $eventId, string $type, array $payload): void;
}
