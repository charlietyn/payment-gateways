<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Tests\Unit;

use Ronu\PaymentGateways\Contracts\Gateway;
use Ronu\PaymentGateways\GatewayFactory;
use Ronu\PaymentGateways\Payment;
use Ronu\PaymentGateways\Services;
use Ronu\PaymentGateways\Testing\FakeMercadoPago;
use Ronu\PaymentGateways\Testing\GatewayConformanceTestCase;

/** The conformance kit on every CI run: the Mercado Pago driver against FakeMercadoPago. */
final class FakeMercadoPagoConformanceTest extends GatewayConformanceTestCase
{
    private FakeMercadoPago $fake;

    protected function gateway(): Gateway
    {
        $this->fake = new FakeMercadoPago();

        return GatewayFactory::make('plaza', [
            'credentials' => ['access_token' => 'TEST-conformance'],
            'webhooks' => ['secret' => 'whsec_conformance'],
            'emulator' => ['api_url' => 'http://plaza.test'],
        ], new Services($this->fake->client(), sleep: static fn () => null));
    }

    protected function currency(): string
    {
        return 'ARS';
    }

    protected function buyerPays(Gateway $gateway, string $resourceId): void
    {
        $this->fake->pay($resourceId);
    }

    protected function unknownPaymentId(): string
    {
        return 'ORD' . strtoupper(bin2hex(random_bytes(13)));
    }

    protected function signedWebhook(Gateway $gateway, Payment $payment): array
    {
        return $this->fake->signedWebhook($payment->id, 'whsec_conformance');
    }
}
