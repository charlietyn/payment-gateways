<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * What a merchant asks for. Provider-neutral on purpose: each driver translates
 * it into its own create call.
 */
final readonly class CreatePayment
{
    /**
     * @param  string  $reference  Your order id. Sent as external_reference (Mercado Pago),
     *                             metadata.order_id (Stripe) and custom_id (PayPal).
     * @param  string  $idempotencyKey  Same key ⇒ same provider resource, even after a lost response.
     * @param  string|null  $returnUrl  Where the provider sends the buyer back, when it does.
     *                                   Required by createCheckout() on Stripe and PayPal.
     * @param  PaymentMethod|null  $paymentMethod  Embedded checkout: the token your page made.
     *                                             Null: the driver's default (Mercado Pago: a ticket).
     * @param  DateTimeImmutable|null  $expiresAt  Hosted checkout: when the page stops accepting payment.
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public Money $amount,
        public string $reference,
        public string $idempotencyKey,
        public ?string $description = null,
        public ?string $returnUrl = null,
        public ?string $cancelUrl = null,
        public ?Payer $payer = null,
        public CaptureMode $captureMode = CaptureMode::Automatic,
        public array $metadata = [],
        public ?PaymentMethod $paymentMethod = null,
        public ?DateTimeImmutable $expiresAt = null,
    ) {
        if ($amount->isZero()) {
            throw new InvalidArgumentException('A payment needs a positive amount.');
        }
        if ($reference === '' || strlen($reference) > 127) {
            throw new InvalidArgumentException('The reference must have 1 to 127 characters.');
        }
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            throw new InvalidArgumentException('The idempotency key must have 1 to 128 characters.');
        }
    }
}
