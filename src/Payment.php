<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways;

/**
 * A provider payment resource, read back and mapped.
 */
final readonly class Payment
{
    /** @var array<string, mixed> */
    public array $raw;

    /**
     * @param  string  $id  The provider resource id (order / payment intent). Store it.
     * @param  string|null  $reference  Your reference as the provider echoes it. Compare it
     *                                  with yours before trusting the payment.
     * @param  string|null  $transactionId  The money movement inside the resource
     *                                      (Mercado Pago payment, Stripe charge, PayPal capture).
     * @param  array<string, mixed>  $raw  The provider body, card tokens removed. For audit only.
     */
    public function __construct(
        public string $gateway,
        public string $id,
        public PaymentStatus $status,
        public Money $amount,
        public ?string $reference,
        public ?string $providerStatus,
        public ?string $providerStatusDetail = null,
        public ?string $transactionId = null,
        public ?Money $capturedAmount = null,
        public ?Money $refundedAmount = null,
        public ?NextAction $nextAction = null,
        array $raw = [],
        /** Stripe: what Stripe.js / Elements confirms on your page. Not a secret for your server, but per payment. */
        public ?string $clientSecret = null,
    ) {
        $this->raw = \Ronu\PaymentGateways\Support\SensitiveData::redact($raw);
    }

    /** The reference and the amount are the ones you expect. Check before fulfilling. */
    public function matches(string $reference, Money $amount): bool
    {
        return $this->reference === $reference && $this->amount->equals($amount);
    }
}
