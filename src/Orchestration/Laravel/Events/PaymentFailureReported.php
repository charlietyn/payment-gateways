<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration\Laravel\Events;

/**
 * A failure the orchestration answered instead of throwing (see Contracts\FailureReporter).
 *
 * Dispatched synchronously, after the durable state is committed. A listener typically hands
 * `$error` to the application's exception handler: `report($event->error)`. The error is the
 * library's own exception and never carries the HTTP request, its headers or its body.
 */
final class PaymentFailureReported
{
    /** @param array<string, string> $context Identifiers only: operation, intent, account, route. */
    public function __construct(
        public readonly string $stage,
        public readonly \Throwable $error,
        public readonly array $context = [],
    ) {
    }
}
