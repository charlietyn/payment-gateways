<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration;

use Ronu\PaymentGateways\Orchestration\Contracts\{AccountResolver, RefundEvidenceReader};

/** Trusted reconciliation port. A notification identifies a resource; it cannot certify returned money. */
final class ExternalRefundCoordinator
{
    public function __construct(private PostgresStore $store, private AccountResolver $accounts, private RefundEvidenceReader $reader)
    {
    }

    public function observe(string $intentReference, string $refundId): bool
    {
        if ($refundId === '' || strlen($refundId) > 128) {
            throw new PaymentError('refund_evidence_invalid', 422);
        }
        $intent = $this->store->intent($intentReference) ?? throw new PaymentError('not_found', 404);
        if ($intent['provider_id'] === null || $intent['captured_minor'] === '0') {
            throw new PaymentError('capture_required', 409);
        }
        $account = $this->accounts->historical($intent['account_alias'], $intent['account_identity']);
        if ($account->identity() !== $intent['account_identity']) {
            throw new PaymentError('account_identity_changed', 503);
        }
        $this->store->assertProviderIoAllowed();
        // Empty remoteKey means no local command is asserted; only this individual ID can resolve it.
        $refund = $this->reader->read($account, $intent['provider_id'], $refundId, '');
        if ($refund === null) {
            return false;
        }
        if ($refund->id !== $refundId || $refund->paymentId !== $intent['provider_id'] || !$refund->succeeded() || $refund->amount->currency !== $intent['currency'] || $refund->amount->minor < 1) {
            throw new PaymentError('refund_evidence_invalid', 503);
        }
        return $this->store->recordExternalRefund($intent, $refund);
    }
}
