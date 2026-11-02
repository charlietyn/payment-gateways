<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration\Laravel\Products\Events;

/** The payment was captured and the subject is now paid. Grant access, ship, send the receipt… here. */
final class PaymentSubjectDelivered extends PaymentSubjectEvent
{
}
