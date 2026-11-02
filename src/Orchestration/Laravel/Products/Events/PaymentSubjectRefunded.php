<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration\Laravel\Products\Events;

/** A refund completed. `full` tells whether everything paid has now been returned (revoke access here). */
final class PaymentSubjectRefunded extends PaymentSubjectEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(string $eventId, string $subject, int $attempt, array $payload, public readonly int $refundedMinor, public readonly bool $full)
    {
        parent::__construct($eventId, $subject, $attempt, $payload);
    }
}
