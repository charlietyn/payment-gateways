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
        // Checkout Pro: the intent names the preference; its refunds live on the payment.
        $refund = $account->gateway->getRefund(ProviderView::refundTarget($account, $paymentId), $refundId);
        return $refund === null ? null : ProviderView::asIntentRefund($account, $refund, $paymentId);
    }
}
