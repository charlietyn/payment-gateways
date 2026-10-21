<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Exceptions;

use RuntimeException;

/** Answer 401/400 and do nothing else. */
final class InvalidWebhookSignature extends RuntimeException
{
    public const MALFORMED = 'malformed';
    public const MISMATCH = 'signature_mismatch';
    public const OUTSIDE_TOLERANCE = 'timestamp_outside_tolerance';
    public const NOT_CONFIGURED = 'not_configured';
    public const UNTRUSTED_CERTIFICATE = 'untrusted_certificate';

    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : "Invalid webhook signature: {$reason}");
    }
}
