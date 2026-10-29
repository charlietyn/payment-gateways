<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Contracts;

use Ronu\PaymentGateways\Dispute;

/** Optional read capability; the caller must already know the individual case identity. */
interface DisputeLookup
{
    public function getDispute(string $paymentId, string $disputeId, string $merchantId): ?Dispute;
}
