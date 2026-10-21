<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Tests\Integration;

use PHPUnit\Framework\TestCase;

/** See UsesEmulator. */
abstract class EmulatorTestCase extends TestCase
{
    use UsesEmulator;
}
