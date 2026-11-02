<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration\Laravel\Products\Events;

/** The money has to go back (see PaymentSubjectRejected). It does NOT mean the refund happened. */
final class PaymentSubjectCompensationRequested extends PaymentSubjectEvent
{
}
