<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration;

use Ronu\PaymentGateways\Money;

/** A verified individual dispute lifecycle, not an inference from a payment status. */
final readonly class DisputeEvidence
{
    public function __construct(public string $id, public string $paymentId, public Money $amount, public string $state)
    {
        if ($id === '' || strlen($id) > 128 || $paymentId === '' || strlen($paymentId) > 128 || $amount->minor < 1 || !in_array($state, ['opened','won','lost'], true)) {
            throw new PaymentError('dispute_evidence_invalid', 422);
        }
    }
}
