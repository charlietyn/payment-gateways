<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Exceptions;

/** The operation does not apply to the payment in its current state. */
final class UnsupportedOperation extends GatewayException
{
}
