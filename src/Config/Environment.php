<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Config;

/**
 * Where a gateway sends its requests. Each driver knows its hosts for sandbox
 * and live; the emulator host comes from `emulator.api_url`.
 */
enum Environment: string
{
    /** The Payment Emulator Lab (Plaza, Vector, Harbor). */
    case Emulator = 'emulator';
    /** The provider's test mode: test credentials, no real money. */
    case Sandbox = 'sandbox';
    /** Real money. */
    case Live = 'live';
}
