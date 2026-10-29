<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration;

final class PaymentError extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $httpStatus)
    {
        // Never chain a provider request, SQL exception or buyer data into public errors.
        parent::__construct($reason);
    }
}
