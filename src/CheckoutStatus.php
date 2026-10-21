<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways;

enum CheckoutStatus: string
{
    /** The buyer has not finished on the provider page yet. */
    case Open = 'open';
    /** The buyer finished: a payment exists. Read it with getPayment($checkout->paymentId). */
    case Completed = 'completed';
    /** Nobody paid in time, or the session was abandoned. */
    case Expired = 'expired';
}
