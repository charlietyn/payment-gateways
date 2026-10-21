<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways;

/**
 * What a webhook says happened, in the same words for every provider:
 *
 *     match ($event->kind) {
 *         EventType::PaymentCaptured, EventType::CheckoutCompleted => $this->reconcile($event),
 *         EventType::DisputeOpened => $this->alertSupport($event),
 *         default => null,
 *     };
 *
 * It is a hint for routing, never the state: re-read with getPayment() before acting.
 * Provider types this library does not know map to PaymentUpdated (when they are
 * about a payment) or Unknown.
 */
enum EventType: string
{
    /** Something changed; the notification does not say what (Mercado Pago `payment.updated`). */
    case PaymentUpdated = 'payment.updated';
    /** The buyer approved; capture() to take the money (PayPal). */
    case PaymentApproved = 'payment.approved';
    case PaymentAuthorized = 'payment.authorized';
    case PaymentCaptured = 'payment.captured';
    case PaymentFailed = 'payment.failed';
    case PaymentCanceled = 'payment.canceled';
    /** Fully or partially: getPayment() says which. */
    case PaymentRefunded = 'payment.refunded';
    case DisputeOpened = 'dispute.opened';
    case DisputeClosed = 'dispute.closed';
    case CheckoutCompleted = 'checkout.completed';
    case CheckoutExpired = 'checkout.expired';
    case Unknown = 'unknown';
}
