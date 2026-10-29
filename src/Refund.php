<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways;

final readonly class Refund
{
    /** @var array<string, mixed> */
    public array $raw;

    /**
     * @param  Payment|null  $payment  The payment after the refund, when the provider returns it.
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $id,
        public Money $amount,
        public string $status,
        public string $paymentId,
        public ?Payment $payment = null,
        array $raw = [],
    ) {
        $this->raw = \Ronu\PaymentGateways\Support\SensitiveData::redact($raw);
    }

    public function succeeded(): bool
    {
        return in_array(strtolower($this->status), ['succeeded', 'completed', 'processed', 'approved'], true);
    }
}
