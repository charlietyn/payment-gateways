<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways;

use DateTimeImmutable;

/**
 * A provider-hosted payment page: Mercado Pago Checkout Pro preference, Stripe
 * Checkout Session, PayPal order approval.
 *
 * Send the buyer to $url. The payment it produces arrives later: through the
 * webhook, or getCheckout($id)->paymentId once the buyer is back.
 */
final readonly class Checkout
{
    /**
     * @param  string  $id  The checkout resource (preference / session / order). Store it.
     * @param  string|null  $url  Where the buyer pays. Null once the checkout is no longer open.
     * @param  bool  $returnsToMerchant  The page sends the buyer back to returnUrl. False on the
     *                                   Plaza and Vector emulator pages: poll your status page.
     * @param  string|null  $paymentId  The payment the checkout produced, for getPayment().
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $gateway,
        public string $id,
        public CheckoutStatus $status,
        public ?string $url,
        public bool $returnsToMerchant,
        public ?string $paymentId = null,
        public ?string $reference = null,
        public ?DateTimeImmutable $expiresAt = null,
        public array $raw = [],
    ) {}

    /**
     * A checkout that IS a payment: PayPal orders, and the emulator pages, where
     * the payment exists from the start and the checkout id is the payment id.
     */
    public static function forPayment(Payment $payment): self
    {
        $status = match ($payment->status) {
            PaymentStatus::Created, PaymentStatus::RequiresAction => CheckoutStatus::Open,
            PaymentStatus::Canceled => CheckoutStatus::Expired,
            default => CheckoutStatus::Completed,
        };

        return new self(
            gateway: $payment->gateway,
            id: $payment->id,
            status: $status,
            url: $status === CheckoutStatus::Open ? $payment->nextAction?->url : null,
            returnsToMerchant: $payment->nextAction !== null && $payment->nextAction->returnsToMerchant,
            paymentId: $payment->id,
            reference: $payment->reference,
            raw: $payment->raw,
        );
    }
}
