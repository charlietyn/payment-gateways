<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration;

use Ronu\PaymentGateways\Contracts\RefundLookup;
use Ronu\PaymentGateways\Refund;
use Ronu\PaymentGateways\Orchestration\Contracts\RefundEvidenceReader;

final class GatewayRefundEvidenceReader implements RefundEvidenceReader
{
    public function read(Account $account, string $paymentId, ?string $refundId, string $remoteKey): ?Refund
    {
        // A missing ID cannot be recovered by matching amount or inventing a correlation.
        if ($refundId === null) {
            return null;
        }
        if (!$account->gateway instanceof RefundLookup) {
            throw new PaymentError('refund_lookup_unsupported', 503);
        }
        return $account->gateway->getRefund($paymentId, $refundId);
    }
}
