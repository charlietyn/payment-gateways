<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways;

use GuzzleHttp\ClientInterface;
use Ronu\PaymentGateways\Config\GatewayConfig;
use Ronu\PaymentGateways\Contracts\Gateway;
use Ronu\PaymentGateways\Drivers\MercadoPago\MercadoPagoGateway;
use Ronu\PaymentGateways\Drivers\PayPal\PayPalGateway;
use Ronu\PaymentGateways\Drivers\Stripe\StripeGateway;
use Ronu\PaymentGateways\Exceptions\ConfigurationException;

/**
 * Builds a gateway from a readable config (see GatewayConfig) or a preset.
 *
 *     GatewayFactory::make('shop', [
 *         'driver' => 'stripe',
 *         'environment' => 'live',
 *         'credentials' => ['secret_key' => 'sk_live_…'],
 *         'webhooks' => ['secret' => 'whsec_…'],
 *     ]);
 *
 *     // A preset fills driver, environment and defaults; your config wins.
 *     GatewayFactory::make('plaza', ['credentials' => ['access_token' => 'TEST-…'], 'emulator' => ['api_url' => 'http://localhost:8080']]);
 *
 * A name that is not a preset needs 'driver' (or 'preset'). New providers plug in with extend().
 */
final class GatewayFactory
{
    /**
     * name => config. The emulator presets differ from the real ones only in
     * `environment` and the `emulator` section: the driver is the same.
     */
    public const PRESETS = [
        'plaza' => ['driver' => 'mercadopago', 'environment' => 'emulator', 'options' => ['currency' => 'ARS'], 'emulator' => ['scenario' => 'plaza/payment-pending']],
        'mercadopago_sandbox' => ['driver' => 'mercadopago', 'environment' => 'sandbox', 'options' => ['currency' => 'ARS']],
        'mercadopago' => ['driver' => 'mercadopago', 'environment' => 'live', 'options' => ['currency' => 'ARS']],

        'vector' => ['driver' => 'stripe', 'environment' => 'emulator', 'emulator' => ['scenario' => 'vector/payment-requires-action']],
        'stripe_test' => ['driver' => 'stripe', 'environment' => 'sandbox'],
        'stripe' => ['driver' => 'stripe', 'environment' => 'live'],

        'harbor' => ['driver' => 'paypal', 'environment' => 'emulator'],
        'paypal_sandbox' => ['driver' => 'paypal', 'environment' => 'sandbox'],
        'paypal' => ['driver' => 'paypal', 'environment' => 'live'],
    ];

    /** @var array<string, callable(GatewayConfig, Services): Gateway> */
    private static array $extensions = [];

    /**
     * @param  array<string, mixed>  $config
     * @param  ClientInterface|Services|null  $services  A Guzzle client, or Services for a cache too.
     */
    public static function make(string $name, array $config = [], ClientInterface|Services|null $services = null): Gateway
    {
        $settings = self::config($name, $config);
        $driver = self::drivers()[$settings->driver]
            ?? throw new ConfigurationException("Payment gateway '{$name}': unknown driver '{$settings->driver}'. Registered: " . implode(', ', array_keys(self::drivers())) . '.');

        return $driver($settings, Services::from($services));
    }

    /**
     * The validated config a gateway would be built with: preset merged, nulls dropped
     * (an unset env() keeps the preset default).
     *
     * @param  array<string, mixed>  $config
     */
    public static function config(string $name, array $config = []): GatewayConfig
    {
        $preset = $config['preset'] ?? (isset($config['driver']) ? null : $name);
        unset($config['preset']);

        if ($preset !== null) {
            $defaults = self::PRESETS[(string) $preset]
                ?? throw new ConfigurationException("Payment gateway '{$name}': unknown preset '{$preset}'. Known: " . implode(', ', array_keys(self::PRESETS)) . '. Or set \'driver\'.');
            $config = self::merge($defaults, $config);
        }

        return GatewayConfig::fromArray($name, self::withoutNulls($config));
    }

    /**
     * Plug in another provider:
     *
     *     GatewayFactory::extend('adyen', fn (GatewayConfig $config, Services $services) => new AdyenGateway($config, $services));
     *
     * @param  callable(GatewayConfig, Services): Gateway  $factory
     */
    public static function extend(string $driver, callable $factory): void
    {
        self::$extensions[$driver] = $factory;
    }

    /** @return array<string, callable(GatewayConfig, Services): Gateway> */
    public static function drivers(): array
    {
        return self::$extensions + [
            'mercadopago' => fn (GatewayConfig $config, Services $services): Gateway => new MercadoPagoGateway($config, $services),
            'stripe' => fn (GatewayConfig $config, Services $services): Gateway => new StripeGateway($config, $services),
            'paypal' => fn (GatewayConfig $config, Services $services): Gateway => new PayPalGateway($config, $services),
        ];
    }

    /**
     * Maps merge key by key; lists (cert_hosts…) are replaced whole.
     *
     * @param  array<array-key, mixed>  $base
     * @param  array<array-key, mixed>  $override
     * @return array<array-key, mixed>
     */
    private static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            $base[$key] = is_array($value) && ! array_is_list($value) && is_array($base[$key] ?? null)
                ? self::merge($base[$key], $value)
                : $value;
        }

        return $base;
    }

    /**
     * @param  array<array-key, mixed>  $config
     * @return array<array-key, mixed>
     */
    private static function withoutNulls(array $config): array
    {
        foreach ($config as $key => $value) {
            if ($value === null) {
                unset($config[$key]);
            } elseif (is_array($value)) {
                $config[$key] = self::withoutNulls($value);
            }
        }

        return $config;
    }
}
