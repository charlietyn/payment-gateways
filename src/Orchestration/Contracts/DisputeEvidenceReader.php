<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration\Contracts;

use Ronu\PaymentGateways\Orchestration\{Account, DisputeEvidence};

interface DisputeEvidenceReader
{
    /** Independently read this resource from the historical account. Unknown amount/outcome returns null. */
    public function read(Account $account, string $paymentId, string $disputeId): ?DisputeEvidence;
}
