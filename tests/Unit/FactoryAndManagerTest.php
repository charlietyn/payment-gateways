<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Tests\Unit;

use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\TestCase;
use Ronu\PaymentGateways\Capability;
use Ronu\PaymentGateways\Config\Environment;
use Ronu\PaymentGateways\Config\GatewayConfig;
use Ronu\PaymentGateways\Contracts\Gateway;
use Ronu\PaymentGateways\Contracts\HostedCheckout;
use Ronu\PaymentGateways\Drivers\MercadoPago\MercadoPagoGateway;
use Ronu\PaymentGateways\Drivers\PayPal\PayPalGateway;
use Ronu\PaymentGateways\Drivers\Stripe\StripeGateway;
use Ronu\PaymentGateways\Exceptions\ConfigurationException;
use Ronu\PaymentGateways\GatewayFactory;
use Ronu\PaymentGateways\Laravel\PaymentGatewaysManager;

final class FactoryAndManagerTest extends TestCase
{
    private const CREDENTIALS = ['access_token' => 'TEST-1', 'secret_key' => 'sk_test_1', 'client_id' => 'a', 'client_secret' => 'b'];

    public function test_every_preset_builds_its_driver_and_offers_a_hosted_checkout(): void
    {
        $expected = [
            'plaza' => MercadoPagoGateway::class, 'mercadopago_sandbox' => MercadoPagoGateway::class, 'mercadopago' => MercadoPagoGateway::class,
            'vector' => StripeGateway::class, 'stripe_test' => StripeGateway::class, 'stripe' => StripeGateway::class,
            'harbor' => PayPalGateway::class, 'paypal_sandbox' => PayPalGateway::class, 'paypal' => PayPalGateway::class,
        ];

        foreach ($expected as $preset => $class) {
            $gateway = GatewayFactory::make($preset, ['credentials' => self::CREDENTIALS, 'emulator' => ['api_url' => 'http://emu.test']]);
            $this->assertInstanceOf($class, $gateway, $preset);
            $this->assertInstanceOf(HostedCheckout::class, $gateway, $preset);
            $this->assertTrue($gateway->supports(Capability::HostedCheckout), $preset);
        }
    }

    public function test_a_named_gateway_with_a_driver_needs_no_preset(): void
    {
        $config = GatewayFactory::config('shop', [
            'driver' => 'stripe',
            'environment' => 'sandbox',
            'credentials' => ['secret_key' => 'sk_test_1'],
            'webhooks' => ['secret' => null],   // env() unset: dropped, not an empty secret
        ]);

        $this->assertSame('stripe', $config->driver);
        $this->assertSame(Environment::Sandbox, $config->environment);
        $this->assertSame([], $config->webhooks);
        $this->assertSame('https://api.stripe.com', $config->resolveBaseUrl(StripeGateway::HOSTS, 'vector'));
    }

    public function test_a_preset_fills_the_blanks_and_the_config_wins(): void
    {
        $config = GatewayFactory::config('checkout', [
            'preset' => 'plaza',
            'emulator' => ['api_url' => 'http://emu.test/', 'scenario' => 'plaza/payment-approved'],
            'options' => ['currency' => 'BRL'],
        ]);

        $this->assertSame('mercadopago', $config->driver);
        $this->assertTrue($config->isEmulator());
        $this->assertSame('http://emu.test/plaza', $config->resolveBaseUrl(MercadoPagoGateway::HOSTS, 'plaza'));
        $this->assertSame(['X-Emulator-Scenario' => 'plaza/payment-approved'], $config->emulatorHeaders());
        $this->assertSame('BRL', $config->option('currency'));
    }

    public function test_configuration_mistakes_fail_at_construction_with_a_readable_message(): void
    {
        $cases = [
            'credentials.secret_key is required' => fn () => GatewayFactory::make('stripe'),
            'credentials.client_secret is required' => fn () => GatewayFactory::make('paypal', ['credentials' => ['client_id' => 'x']]),
            "unknown preset 'nope'" => fn () => GatewayFactory::make('nope'),
            'unknown key(s) secret_key' => fn () => GatewayFactory::make('stripe', ['secret_key' => 'sk_live_flat']),
            "'environment' must be emulator, sandbox or live" => fn () => GatewayFactory::make('x', ['driver' => 'stripe', 'environment' => 'prod']),
            'emulator.api_url is required' => fn () => GatewayFactory::make('vector', ['credentials' => ['secret_key' => 'sk_test_1']]),
            "unknown driver 'adyen'" => fn () => GatewayFactory::make('x', ['driver' => 'adyen', 'environment' => 'live']),
        ];

        foreach ($cases as $message => $bad) {
            try {
                $bad();
                $this->fail("expected: {$message}");
            } catch (ConfigurationException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function test_other_providers_plug_in_with_extend(): void
    {
        $gateway = $this->createStub(Gateway::class);
        GatewayFactory::extend('acme', function (GatewayConfig $config, ?ClientInterface $client) use ($gateway): Gateway {
            $this->assertSame('pk_acme', $config->credential('api_key'));

            return $gateway;
        });

        $this->assertSame($gateway, GatewayFactory::make('acme-shop', ['driver' => 'acme', 'environment' => 'live', 'credentials' => ['api_key' => 'pk_acme']]));
    }

    public function test_the_manager_resolves_named_gateways_once(): void
    {
        $manager = new PaymentGatewaysManager([
            'default' => 'shop',
            'gateways' => [
                'shop' => ['preset' => 'plaza', 'emulator' => ['api_url' => 'http://emu.test'], 'credentials' => ['access_token' => 'TEST-1']],
                'eu' => ['driver' => 'stripe', 'environment' => 'live', 'credentials' => ['secret_key' => 'sk_test_1']],
            ],
        ]);

        $this->assertSame('mercadopago', $manager->gateway()->name());
        $this->assertSame($manager->gateway('shop'), $manager->gateway());
        $this->assertSame('stripe', $manager->gateway('eu')->name());
        $this->assertSame(['shop', 'eu'], $manager->names());

        $this->expectException(ConfigurationException::class);
        $manager->gateway('missing');
    }

    public function test_the_published_config_builds_every_gateway(): void
    {
        // env() comes from illuminate/support (require-dev); unset variables keep the defaults.
        $config = require __DIR__ . '/../../config/payment-gateways.php';
        $config['gateways'] = array_map(fn (array $settings) => ['credentials' => self::CREDENTIALS] + $settings, $config['gateways']);
        $manager = new PaymentGatewaysManager($config);

        foreach ($manager->names() as $name) {
            $this->assertTrue($manager->gateway($name)->supports(Capability::HostedCheckout), $name);
        }
    }
}
