<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Laravel;

use Ronu\PaymentGateways\Contracts\Gateway;
use Ronu\PaymentGateways\Exceptions\ConfigurationException;
use Ronu\PaymentGateways\GatewayFactory;
use Ronu\PaymentGateways\Services;

/**
 * Named gateways from config/payment-gateways.php, built once per process.
 *
 *     app(PaymentGatewaysManager::class)->gateway();          // the default
 *     app(PaymentGatewaysManager::class)->gateway('paypal');  // a named one
 */
final class PaymentGatewaysManager
{
    /** @var array<string, Gateway> */
    private array $resolved = [];

    /**
     * @param  array<string, mixed>  $config  The payment-gateways config.
     * @param  Services|null  $services  The service provider passes the app cache (shared PayPal tokens).
     */
    public function __construct(private readonly array $config, private readonly ?Services $services = null) {}

    public function gateway(?string $name = null): Gateway
    {
        $name ??= (string) ($this->config['default'] ?? '');
        if ($name === '') {
            throw new ConfigurationException('payment-gateways.default is not set.');
        }

        return $this->resolved[$name] ??= $this->build($name);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys((array) ($this->config['gateways'] ?? []));
    }

    /** A gateway's settings: 'driver' + 'environment', or a 'preset' (see GatewayFactory::PRESETS). */
    private function build(string $name): Gateway
    {
        $settings = $this->config['gateways'][$name] ?? null;
        if (! is_array($settings)) {
            throw new ConfigurationException("payment-gateways.gateways.{$name} is not configured. Configured: " . implode(', ', $this->names()) . '.');
        }

        return GatewayFactory::make($name, $settings, $this->services);
    }
}
