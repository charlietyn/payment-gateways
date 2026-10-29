<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration;

use Ronu\PaymentGateways\Contracts\DisputeLookup;
use Ronu\PaymentGateways\Orchestration\Contracts\DisputeEvidenceReader;

final class GatewayDisputeEvidenceReader implements DisputeEvidenceReader
{
    public function read(Account $account, string $paymentId, string $disputeId): ?DisputeEvidence
    {
        if (!$account->gateway instanceof DisputeLookup) {
            throw new PaymentError('dispute_lookup_unsupported', 503);
        }
        $evidence = $account->gateway->getDispute($paymentId, $disputeId, $account->merchant);
        return $evidence === null ? null : new DisputeEvidence($evidence->id, $evidence->paymentId, $evidence->amount, $evidence->state);
    }
}
