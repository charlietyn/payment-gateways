<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration\Laravel;

use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use Ronu\PaymentGateways\Exceptions\GatewayException;
use Ronu\PaymentGateways\Orchestration\Contracts\FailureReporter;
use Ronu\PaymentGateways\Orchestration\Laravel\Events\PaymentFailureReported;

/** One log line and one event per hidden failure. Neither the log nor the event can break a payment. */
final class FailureReporting implements FailureReporter
{
    public function __construct(private LoggerInterface $logger, private ?Dispatcher $events = null)
    {
    }

    public function report(string $stage, \Throwable $error, array $context = []): void
    {
        try {
            // The message of a foreign exception may quote a query or a URL; the class and place do not.
            $this->logger->error('payments: {stage} failed ({exception})', $context + [
                'stage' => $stage,
                'exception' => $error::class,
                'at' => basename($error->getFile()).':'.$error->getLine(),
                'retryable' => $error instanceof GatewayException ? $error->retryable : null,
                'status' => $error instanceof GatewayException ? $error->httpStatus : null,
                'provider_code' => $error instanceof GatewayException ? $error->providerCode : null,
            ]);
        } catch (\Throwable) {
        }
        try {
            $this->events?->dispatch(new PaymentFailureReported($stage, $error, $context));
        } catch (\Throwable) {
            // A failing listener belongs to the host; the payment answer is already decided.
        }
    }
}
