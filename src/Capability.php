<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways;

/**
 * What a driver can do, so a consumer asks before it calls instead of catching
 * UnsupportedOperation:
 *
 *     if ($gateway->supports(Capability::PartialCapture)) { … }
 */
enum Capability: string
{
    /** createCheckout(): the buyer pays on the provider's page (Checkout Pro, Checkout Session, PayPal approval). */
    case HostedCheckout = 'hosted_checkout';
    /** createPayment() with a PaymentMethod token made in your page (Brick, Elements, PayPal JS SDK). */
    case EmbeddedCheckout = 'embedded_checkout';
    /** CaptureMode::Manual: authorize now, capture() later. */
    case ManualCapture = 'manual_capture';
    /** capture() for less than the authorized amount. */
    case PartialCapture = 'partial_capture';
    /** refund() for less than what was captured, more than once. */
    case PartialRefund = 'partial_refund';
    /** Cash vouchers paid later (Pago Fácil, Rapipago, boleto). */
    case OfflinePayments = 'offline_payments';
}
