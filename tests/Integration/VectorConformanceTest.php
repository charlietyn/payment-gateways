<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Tests\Integration;

use Ronu\PaymentGateways\Contracts\Gateway;
use Ronu\PaymentGateways\GatewayFactory;
use Ronu\PaymentGateways\Testing\GatewayConformanceTestCase;

/** The conformance kit, Stripe driver against the emulator's Vector profile. */
final class VectorConformanceTest extends GatewayConformanceTestCase
{
    use UsesEmulator;

    private static ?Gateway $vector = null;

    protected function gateway(): Gateway
    {
        if (self::$vector === null) {
            $secret = 'whsec_' . bin2hex(random_bytes(16));
            $app = self::application('vector', 'vector/payment-requires-action', $secret);
            self::$vector = GatewayFactory::make('vector', [
                'credentials' => ['secret_key' => $app['credentials']['accessToken']],
                'webhooks' => ['secret' => $secret],
                'emulator' => ['api_url' => self::$emulator],
            ]);
        }

        return self::$vector;
    }

    protected function currency(): string
    {
        return 'USD';
    }

    protected function buyerPays(Gateway $gateway, string $resourceId): void
    {
        self::buyerConfirms('vector', $resourceId, 'card');
    }

    protected function unknownPaymentId(): string
    {
        return 'pi_' . bin2hex(random_bytes(12));
    }

    /** Vector ignores `expand[]=latest_charge`, where Stripe keeps refunds. */
    protected function readsRefundsBack(): bool
    {
        return false;
    }

    /** The kit is right; the emulator is not (see docs/PLAN-2.md, mejoras al emulador). */
    public function test_a_captured_payment_cannot_be_canceled(): void
    {
        $this->markTestIncomplete('The emulator answers 500 to cancel a captured payment (assertTransition throws); a provider answers 400.');
    }
}
