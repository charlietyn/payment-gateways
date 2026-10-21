<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The provider refused or could not be reached.
 *
 * `retryable` is the only thing most callers need: true for network failures,
 * timeouts, 408, 429 and 5xx — retry with the SAME idempotency key.
 */
class GatewayException extends RuntimeException
{
    /** @param  array<string, mixed>|null  $body */
    public function __construct(
        string $message,
        public readonly bool $retryable = false,
        public readonly ?int $httpStatus = null,
        public readonly ?string $providerCode = null,
        public readonly ?array $body = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
