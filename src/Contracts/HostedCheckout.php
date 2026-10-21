<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Contracts;

use Ronu\PaymentGateways\Checkout;
use Ronu\PaymentGateways\CreatePayment;
use Ronu\PaymentGateways\Exceptions\GatewayException;

/**
 * The buyer pays on the provider's page; your server never sees a card.
 *
 *  1. createCheckout() → redirect to $checkout->url;
 *  2. the buyer comes back to returnUrl → getCheckout($id) (or wait for the webhook);
 *  3. getPayment($checkout->paymentId) → the usual Gateway flow (capture, refund…).
 */
interface HostedCheckout
{
    /** @throws GatewayException */
    public function createCheckout(CreatePayment $request): Checkout;

    /**
     * Null when the provider does not know the id.
     *
     * @throws GatewayException
     */
    public function getCheckout(string $checkoutId): ?Checkout;
}
