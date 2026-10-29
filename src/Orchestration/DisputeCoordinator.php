<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration;

use Ronu\PaymentGateways\Orchestration\Contracts\{AccountResolver, DisputeEvidenceReader};

/** Read-only provider reconciliation; no customer-facing command and no provider write. */
final class DisputeCoordinator
{
    public function __construct(private PostgresStore $store, private AccountResolver $accounts, private DisputeEvidenceReader $reader)
    {
    }

    public function observe(string $reference, string $disputeId): bool
    {
        if ($disputeId === '' || strlen($disputeId) > 128) {
            throw new PaymentError('dispute_evidence_invalid', 422);
        }
        $intent = $this->store->intent($reference) ?? throw new PaymentError('not_found', 404);
        if ($intent['provider_id'] === null || $intent['captured_minor'] === '0') {
            throw new PaymentError('capture_required', 409);
        }
        $account = $this->accounts->historical($intent['account_alias'], $intent['account_identity']);
        if ($account->identity() !== $intent['account_identity']) {
            throw new PaymentError('account_identity_changed', 503);
        }
        $this->store->assertProviderIoAllowed();
        $evidence = $this->reader->read($account, $intent['provider_id'], $disputeId);
        if ($evidence === null) {
            return false;
        }
        if ($evidence->id !== $disputeId || $evidence->paymentId !== $intent['provider_id'] || $evidence->amount->currency !== $intent['currency']) {
            throw new PaymentError('dispute_evidence_invalid', 503);
        }
        return $this->store->recordDispute($intent, $evidence);
    }
}
