<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration;

use Ronu\PaymentGateways\Money;

final readonly class Quote
{
    public function __construct(public string $subject, public string $revision, public string $ownerScope, public Money $amount, public int $expiresAt)
    {
        if ($subject === '' || strlen($subject) > 128 || $revision === '' || strlen($revision) > 128 || $amount->isZero()) {
            throw new PaymentError('invalid_quote', 422);
        }
    }
}
