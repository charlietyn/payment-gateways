<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration\Contracts;

use Ronu\PaymentGateways\Refund;
use Ronu\PaymentGateways\Orchestration\Account;

/** Read-only provider adapter. Never POST a refund to discover its result. */
interface RefundEvidenceReader
{
    /** Return independently verified individual evidence, or null if identity remains unresolved. */
    public function read(Account $account, string $paymentId, ?string $refundId, string $remoteKey): ?Refund;
}
