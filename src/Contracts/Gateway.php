<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Contracts;

use Ronu\PaymentGateways\Capability;
use Ronu\PaymentGateways\CreatePayment;
use Ronu\PaymentGateways\Exceptions\GatewayException;
use Ronu\PaymentGateways\Exceptions\InvalidWebhookSignature;
use Ronu\PaymentGateways\Money;
use Ronu\PaymentGateways\Payment;
use Ronu\PaymentGateways\Refund;
use Ronu\PaymentGateways\WebhookEvent;

/**
 * One payment contract for every provider.
 *
 * The usual flow:
 *  1. createPayment() → send the buyer to $payment->nextAction->url;
 *  2. a webhook arrives → parseWebhook() → getPayment(paymentIdForEvent());
 *  3. status Approved/Authorized → capture(); Captured → fulfill (after Payment::matches()).
 *
 * Drivers whose provider hosts a payment page also implement HostedCheckout.
 */
interface Gateway
{
    /** The driver: `mercadopago`, `stripe`, `paypal` or a registered one. */
    public function name(): string;

    /** Whether the driver does this. Ask before calling instead of catching UnsupportedOperation. */
    public function supports(Capability $capability): bool;

    /**
     * A payment your page completes: with $request->paymentMethod (a Brick or Elements
     * token, a ticket) or, without it, through Payment::$nextAction / Payment::$clientSecret.
     *
     * @throws GatewayException
     */
    public function createPayment(CreatePayment $request): Payment;

    /**
     * Null when the provider does not know the id.
     *
     * @throws GatewayException
     */
    public function getPayment(string $paymentId): ?Payment;

    /**
     * Take the money of an approved or authorized payment. Null amount = all of it.
     *
     * @throws GatewayException
     */
    public function capture(string $paymentId, ?Money $amount = null, ?string $idempotencyKey = null): Payment;

    /**
     * Release an authorization or abandon a payment nobody paid.
     *
     * @throws GatewayException
     */
    public function cancel(string $paymentId, ?string $idempotencyKey = null): Payment;

    /**
     * Give money back. Null amount = everything still captured.
     *
     * @throws GatewayException
     */
    public function refund(string $paymentId, ?Money $amount, string $idempotencyKey): Refund;

    /**
     * Verify and parse a notification. Pass the RAW body: Stripe and PayPal sign bytes.
     *
     * @param  array<string, string|list<string>>  $headers  Any case.
     *
     * @throws InvalidWebhookSignature
     */
    public function parseWebhook(array $headers, string $rawBody): WebhookEvent;

    /**
     * The payment resource the event is about. May query the provider
     * (PayPal: capture → order through its `up` link).
     *
     * @throws GatewayException
     */
    public function paymentIdForEvent(WebhookEvent $event): ?string;
}
