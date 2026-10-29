<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Contracts;

use Ronu\PaymentGateways\Refund;

/** Optional read-only lookup by individual provider identity. Never resends a refund. */
interface RefundLookup
{
    public function getRefund(string $paymentId, string $refundId): ?Refund;
}
