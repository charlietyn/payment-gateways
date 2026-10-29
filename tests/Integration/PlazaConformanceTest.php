<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Tests\Integration;

use Ronu\PaymentGateways\Contracts\Gateway;
use Ronu\PaymentGateways\GatewayFactory;
use Ronu\PaymentGateways\Contracts\RefundLookup;
use Ronu\PaymentGateways\CreatePayment;
use Ronu\PaymentGateways\Money;
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

    public function test_individual_refunds_remain_queryable_without_reissuing_them(): void
    {
        $gateway = $this->gateway();
        self::assertInstanceOf(RefundLookup::class, $gateway);
        $payment = $gateway->createPayment(new CreatePayment(new Money(10000, 'ARS'), 'lookup-' . bin2hex(random_bytes(8)), bin2hex(random_bytes(16))));
        $this->buyerPays($gateway, $payment->id);
        $first = $gateway->refund($payment->id, new Money(1000, 'ARS'), bin2hex(random_bytes(16)));
        $second = $gateway->refund($payment->id, new Money(1000, 'ARS'), bin2hex(random_bytes(16)));
        self::assertNotSame($first->id, $second->id);
        foreach ([$second, $first, $second] as $expected) {
            $read = $gateway->getRefund($payment->id, $expected->id);
            self::assertSame($expected->id, $read?->id);
            self::assertSame(1000, $read?->amount->minor);
            self::assertTrue($read?->succeeded());
        }
        self::assertNull($gateway->getRefund($payment->id, 'REF_UNKNOWN'));
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
        parent::test_a_captured_payment_cannot_be_canceled();
    }
}
