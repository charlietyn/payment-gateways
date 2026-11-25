<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration\Laravel\Events;

/**
 * A back-office write was answered (0.10.0): who (the operator's non-reversible audit id), what, on
 * which payment, and the outcome. Dispatched after the work committed. Carries no amounts typed by a
 * person beyond the decimal the refund recorded, no keys and no personal data, so a listener may write
 * it to an audit log as is.
 */
final readonly class OperatorActionRecorded
{
    public function __construct(
        public string $operator,
        public string $action,
        public string $reference,
        public string $outcome,
        public ?string $operationReference = null,
        public ?string $amount = null,
        public ?string $currency = null,
    ) {
    }
}
