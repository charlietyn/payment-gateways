<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration\Laravel\Products\Events;

/**
 * Money was captured but the subject could NOT be delivered (already paid, no longer payable or
 * deliverable, expired, amount or currency changed). A compensation request follows; nothing to deliver.
 */
final class PaymentSubjectRejected extends PaymentSubjectEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(string $eventId, string $subject, int $attempt, array $payload, public readonly string $reason)
    {
        parent::__construct($eventId, $subject, $attempt, $payload);
    }
}
