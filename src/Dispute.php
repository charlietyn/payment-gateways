<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways;

/** Individual principal only. Fees, evidence files and buyer data are deliberately absent. */
final readonly class Dispute
{
    public function __construct(public string $id, public string $paymentId, public Money $amount, public string $state)
    {
        if ($id === '' || $paymentId === '' || $amount->minor < 1 || !in_array($state, ['opened', 'won', 'lost'], true)) {
            throw new \InvalidArgumentException('Invalid individual dispute evidence.');
        }
    }
}
