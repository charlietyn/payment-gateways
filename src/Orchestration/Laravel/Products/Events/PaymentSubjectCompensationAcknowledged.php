<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration\Laravel\Products\Events;

/** An operator recorded how a compensation was resolved (payments:run acknowledge-compensation). */
final class PaymentSubjectCompensationAcknowledged extends PaymentSubjectEvent
{
}
