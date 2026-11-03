<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration\Laravel\Setup;

/** Solo códigos controlados: nunca se propagan respuestas HTTP ni secretos. */
final class SetupFailure extends \RuntimeException
{
}
