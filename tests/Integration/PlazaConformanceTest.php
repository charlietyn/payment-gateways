<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Tests\Integration;

use Ronu\PaymentGateways\Contracts\Gateway;
use Ronu\PaymentGateways\GatewayFactory;
use Ronu\PaymentGateways\Testing\GatewayConformanceTestCase;

/** The conformance kit, Mercado Pago driver against the emulator's Plaza profile. */
final class PlazaConformanceTest extends GatewayConformanceTestCase
{
    use UsesEmulator;

    private static ?Gateway $plaza = null;

    protected function gateway(): Gateway
    {
        if (self::$plaza === null) {
            $secret = 'whsec_' . bin2hex(random_bytes(16));
            $app = self::application('plaza', 'plaza/payment-pending', $secret);
            self::$plaza = GatewayFactory::make('plaza', [
                'credentials' => ['access_token' => $app['credentials']['accessToken']],
                'webhooks' => ['secret' => $secret],
                'emulator' => ['api_url' => self::$emulator],
            ]);
        }

        return self::$plaza;
    }

    protected function currency(): string
    {
        return 'ARS';
    }

    protected function buyerPays(Gateway $gateway, string $resourceId): void
    {
        self::buyerConfirms('plaza', $resourceId, 'visa');
    }

    protected function unknownPaymentId(): string
    {
        return 'ORD' . strtoupper(bin2hex(random_bytes(13)));
    }

    /** The kit is right; the emulator is not (see docs/PLAN-2.md, mejoras al emulador). */
    public function test_a_captured_payment_cannot_be_canceled(): void
    {
        $this->markTestIncomplete('The emulator answers 500 to cancel a captured payment (assertTransition throws); a provider answers 400.');
    }
}
