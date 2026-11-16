<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration\Contracts;

/**
 * Where the orchestration tells its host about a failure it deliberately did not let escape.
 *
 * A provider failure during a write becomes a durable UNKNOWN operation, and an unexpected failure
 * of an HTTP action becomes `payment_unavailable`. Both are the right answer for the caller and
 * leave nothing for the host's exception handler to see; this port is the trace.
 */
interface FailureReporter
{
    /** A checkout was sent and its outcome is uncertain; the operation is UNKNOWN. */
    public const CHECKOUT = 'checkout';

    /** A refund was sent and its outcome is uncertain; the refund operation is UNKNOWN. */
    public const REFUND = 'refund';

    /** An HTTP action failed unexpectedly and answered `payment_unavailable`. */
    public const REQUEST = 'request';

    /**
     * Called after the durable state is committed. An implementation that throws is ignored.
     *
     * @param array<string, string> $context Identifiers only (operation, intent, account, route): never payloads or credentials.
     */
    public function report(string $stage, \Throwable $error, array $context = []): void;
}
