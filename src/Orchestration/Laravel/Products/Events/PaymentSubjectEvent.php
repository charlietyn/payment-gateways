<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration\Laravel\Products\Events;

/**
 * Base of the events a TableSubjectAdapter dispatches AFTER its database transaction committed.
 *
 * Delivery is at-least-once: when the same payment event is processed again (a worker retry) the event is
 * dispatched again with the same `eventId`. Listeners MUST be idempotent by `eventId`.
 */
abstract class PaymentSubjectEvent
{
    /**
     * @param string $subject  The product reference (the row), without any attempt suffix.
     * @param int    $attempt  1 for the first payment of the subject, n for a retry after a declined one.
     * @param array<string, mixed> $payload  The orchestration event payload (amounts in minor units).
     */
    public function __construct(
        public readonly string $eventId,
        public readonly string $subject,
        public readonly int $attempt,
        public readonly array $payload,
    ) {
    }
}
