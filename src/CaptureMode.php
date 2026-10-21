<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways;

enum CaptureMode: string
{
    /** Money is taken as soon as the buyer pays (PayPal still needs capture() after approval). */
    case Automatic = 'automatic';
    /** Funds are only authorized; capture() takes them. */
    case Manual = 'manual';
}
