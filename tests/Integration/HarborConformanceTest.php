<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Tests\Integration;

use Ronu\PaymentGateways\Contracts\Gateway;
use Ronu\PaymentGateways\GatewayFactory;
use Ronu\PaymentGateways\Testing\GatewayConformanceTestCase;

/** The conformance kit, PayPal driver against the emulator's Harbor profile. */
final class HarborConformanceTest extends GatewayConformanceTestCase
{
    use UsesEmulator;

    private static ?Gateway $harbor = null;

    protected function gateway(): Gateway
    {
        if (self::$harbor === null) {
            $webhookId = 'WH-' . strtoupper(bin2hex(random_bytes(8)));
            $app = self::application('harbor', 'harbor/default', $webhookId);
            self::$harbor = GatewayFactory::make('harbor', [
                'credentials' => ['client_id' => $app['credentials']['clientId'], 'client_secret' => $app['credentials']['clientSecret']],
                'webhooks' => ['id' => $webhookId],
                'emulator' => ['api_url' => self::$emulator],
            ]);
        }

        return self::$harbor;
    }

    protected function currency(): string
    {
        return 'USD';
    }

    protected function buyerPays(Gateway $gateway, string $resourceId): void
    {
        self::buyerConfirms('harbor', $resourceId, 'harbor');
    }

    protected function unknownPaymentId(): string
    {
        return strtoupper(bin2hex(random_bytes(9)));
    }
}
