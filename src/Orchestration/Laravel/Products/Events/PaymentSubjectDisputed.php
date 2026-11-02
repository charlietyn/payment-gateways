<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration\Laravel\Products\Events;

/** A chargeback changed: `state` is opened, won or lost. */
final class PaymentSubjectDisputed extends PaymentSubjectEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(string $eventId, string $subject, int $attempt, array $payload, public readonly string $state)
    {
        parent::__construct($eventId, $subject, $attempt, $payload);
    }
}
