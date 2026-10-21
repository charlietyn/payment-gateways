<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways;

use GuzzleHttp\ClientInterface;
use Ronu\PaymentGateways\Contracts\Gateway;
use Ronu\PaymentGateways\Drivers\MercadoPago\MercadoPagoOrdersGateway;
use Ronu\PaymentGateways\Drivers\PayPal\PayPalOrdersGateway;
use Ronu\PaymentGateways\Drivers\Stripe\StripePaymentIntentsGateway;
use Ronu\PaymentGateways\Exceptions\ConfigurationException;

/**
 * Builds a gateway from a preset and your credentials.
 *
 *     GatewayFactory::make('plaza', ['access_token' => 'TEST-…', 'webhook_secret' => '…']);
 *     GatewayFactory::make('stripe', ['secret_key' => 'sk_live_…', 'webhook_secret' => 'whsec_…']);
 *
 * Emulator presets take `emulator_url` (API) and `emulator_checkout_url` (buyer pages).
 * The production code path is the same; only the preset changes.
 */
final class GatewayFactory
{
    /** preset => [driver, defaults] */
    public const PRESETS = [
        'mercadopago' => ['mercadopago', ['base_url' => 'https://api.mercadopago.com', 'currency' => 'ARS']],
        'plaza' => ['mercadopago', ['currency' => 'ARS', 'scenario' => 'plaza/payment-pending']],
        'stripe' => ['stripe', ['base_url' => 'https://api.stripe.com', 'confirm_on_create' => false]],
        'vector' => ['stripe', ['confirm_on_create' => true, 'scenario' => 'vector/payment-requires-action', 'redirect_returns_to_merchant' => false]],
        'paypal' => ['paypal', ['base_url' => 'https://api-m.paypal.com']],
        'paypal_sandbox' => ['paypal', ['base_url' => 'https://api-m.sandbox.paypal.com']],
        'harbor' => ['paypal', ['webhook_cert_allow_http' => true]],
    ];

    /** @param  array<string, mixed>  $config */
    public static function make(string $preset, array $config = [], ?ClientInterface $client = null): Gateway
    {
        if (! isset(self::PRESETS[$preset])) {
            throw new ConfigurationException("Unknown payment gateway preset '{$preset}'. Known: " . implode(', ', array_keys(self::PRESETS)));
        }

        [$driver, $defaults] = self::PRESETS[$preset];
        $config = array_replace($defaults, self::emulatorDefaults($preset, $config), array_filter($config, fn ($v) => $v !== null));

        return match ($driver) {
            'mercadopago' => new MercadoPagoOrdersGateway($config, $client),
            'stripe' => new StripePaymentIntentsGateway($config, $client),
            'paypal' => new PayPalOrdersGateway($config, $client),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function emulatorDefaults(string $preset, array $config): array
    {
        $api = rtrim((string) ($config['emulator_url'] ?? ''), '/');
        if (! in_array($preset, ['plaza', 'vector', 'harbor'], true) || $api === '') {
            return [];
        }

        $checkout = rtrim((string) ($config['emulator_checkout_url'] ?? ''), '/');

        return match ($preset) {
            'plaza' => ['base_url' => "{$api}/plaza"] + ($checkout !== '' ? ['checkout_url' => $checkout] : []),
            'vector' => ['base_url' => "{$api}/vector"],
            'harbor' => ['base_url' => "{$api}/harbor", 'webhook_cert_hosts' => [(string) parse_url($api, PHP_URL_HOST)]],
        };
    }
}
