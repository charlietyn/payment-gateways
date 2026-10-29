<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration\Contracts;

/** A durable product receipt for a reviewed business resolution, never proof of a refund. */
interface ManualCompensation
{
    public function acknowledgeCompensation(string $receiptId, string $subject, array $acknowledgement): void;
}
