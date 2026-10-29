<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration\Contracts;

interface Fulfillment
{
    /** Consumer MUST durably deduplicate eventId with its business effect AND decision, including false. Return false if no longer deliverable. */
    public function deliver(string $eventId, string $subject, array $payment): bool;
    /** Durable product compensation request, also deduplicated by eventId; never assumes refund completed. */
    public function compensate(string $eventId, string $subject, array $payment): void;
}
