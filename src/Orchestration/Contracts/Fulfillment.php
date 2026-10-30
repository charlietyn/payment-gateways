<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration\Contracts;

interface Fulfillment
{
    /**
     * Consumer MUST durably deduplicate eventId with its business effect AND decision, including false. Return false if no longer deliverable.
     *
     * @param array<string, mixed> $payment
     */
    public function deliver(string $eventId, string $subject, array $payment): bool;
    /**
     * Durable product compensation request, also deduplicated by eventId; never assumes refund completed.
     *
     * @param array<string, mixed> $payment
     */
    public function compensate(string $eventId, string $subject, array $payment): void;
}
