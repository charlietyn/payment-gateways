<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ronu\PaymentGateways\Drivers\MercadoPago\MercadoPagoOrdersGateway;
use Ronu\PaymentGateways\Drivers\PayPal\PayPalOrdersGateway;
use Ronu\PaymentGateways\Drivers\Stripe\StripePaymentIntentsGateway;
use Ronu\PaymentGateways\Exceptions\ConfigurationException;
use Ronu\PaymentGateways\GatewayFactory;
use Ronu\PaymentGateways\Laravel\PaymentGatewaysManager;

final class FactoryAndManagerTest extends TestCase
{
    public function test_every_preset_builds_its_driver(): void
    {
        $credentials = ['access_token' => 'TEST-1', 'secret_key' => 'sk_test_1', 'client_id' => 'a', 'client_secret' => 'b', 'emulator_url' => 'http://emu.test'];
        $expected = [
            'plaza' => MercadoPagoOrdersGateway::class, 'mercadopago' => MercadoPagoOrdersGateway::class,
            'vector' => StripePaymentIntentsGateway::class, 'stripe' => StripePaymentIntentsGateway::class,
            'harbor' => PayPalOrdersGateway::class, 'paypal' => PayPalOrdersGateway::class, 'paypal_sandbox' => PayPalOrdersGateway::class,
        ];

        foreach ($expected as $preset => $class) {
            $this->assertInstanceOf($class, GatewayFactory::make($preset, $credentials), $preset);
        }
    }

    public function test_missing_credentials_and_unknown_presets_fail_at_construction(): void
    {
        foreach ([fn () => GatewayFactory::make('stripe', []), fn () => GatewayFactory::make('paypal', ['client_id' => 'x']), fn () => GatewayFactory::make('nope')] as $bad) {
            try {
                $bad();
                $this->fail('expected a configuration error');
            } catch (ConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_manager_resolves_named_gateways_once(): void
    {
        $manager = new PaymentGatewaysManager([
            'default' => 'shop',
            'gateways' => [
                'shop' => ['preset' => 'plaza', 'emulator_url' => 'http://emu.test', 'access_token' => 'TEST-1'],
                'eu' => ['preset' => 'stripe', 'secret_key' => 'sk_test_1'],
            ],
        ]);

        $this->assertSame('mercadopago', $manager->gateway()->name());
        $this->assertSame($manager->gateway('shop'), $manager->gateway());
        $this->assertSame('stripe', $manager->gateway('eu')->name());
        $this->assertSame(['shop', 'eu'], $manager->names());

        $this->expectException(ConfigurationException::class);
        $manager->gateway('missing');
    }
}
