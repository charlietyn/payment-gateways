<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration\Laravel;

use Ronu\PaymentGateways\GatewayFactory;
use Ronu\PaymentGateways\Orchestration\{Account, Actor, PaymentError};
use Ronu\PaymentGateways\Orchestration\Contracts\AccountResolver;

final class ConfiguredAccounts implements AccountResolver
{
    /** Longest webhook secret rotation window accepted: 7 days. */
    public const MAX_ROTATION_SECONDS = 604800;

    /** @param array<string, mixed> $configuration */
    public function __construct(private array $configuration)
    {
    }

    public function forNew(Actor $actor, ?string $alias): Account
    {
        $alias ??= $this->configuration['default_account'];
        $actor->require('create', $alias);
        return $this->account($alias);
    }

    public function historical(string $alias, string $identity): Account
    {
        $account = $this->account($alias);
        if ($account->identity() !== $identity) {
            throw new PaymentError('account_identity_changed', 503);
        }
        return $account;
    }

    public function account(string $alias): Account
    {
        $config = $this->configuration['accounts'][$alias] ?? throw new PaymentError('unknown_account', 404);
        $driver = $config['driver'] ?? 'mercadopago';
        if (!isset(self::PRESETS[$driver])) {
            throw new PaymentError('unsupported_account', 503);
        }
        foreach (['access_token', 'webhook_secret', 'api_url', 'checkout_url', ...($driver === 'paypal' ? ['client_id'] : [])] as $key) {
            if (!is_string($config[$key] ?? null) || trim($config[$key]) === '') {
                throw new PaymentError('account_not_ready', 503);
            }
        }
        // This candidate is explicitly local. A URL cannot silently turn it into live transport.
        foreach (['api_url', 'checkout_url'] as $key) {
            $url = parse_url($config[$key]);
            if (!is_array($url) || ($url['scheme'] ?? '') !== 'http' || !in_array($url['host'] ?? '', ['127.0.0.1', 'localhost', '[::1]'], true)
                || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || !in_array($url['path'] ?? '', ['', '/'], true)) {
                throw new PaymentError('unsafe_emulator_endpoint', 503);
            }
        }
        // The preset pins a per-request scenario override. An application never sends one: the
        // behaviour belongs to the emulator application the operator configured, so that a fault
        // scenario bound there is what this account actually meets.
        $gateway = $this->gateway($driver, $config, $config['webhook_secret']);
        [$previousGateway, $previousUntil] = $this->previousWebhookSecret($config, $driver);
        return new Account(
            $alias,
            $config['merchant_id'],
            'emulator',
            (string) ($config['currency'] ?? 'ARS'),
            $config['configuration_version'],
            $gateway,
            [rtrim($config['checkout_url'], '/')],
            (bool) ($this->configuration['accept_new'] ?? false),
            $previousGateway,
            $previousUntil,
            // Mercado Pago may speak Orders (default) or Checkout Pro; the Account refuses anything else.
            isset($config['api']) ? (string) $config['api'] : null,
        );
    }

    /** Emulator preset per driver: Plaza (Mercado Pago), Vector (Stripe), Harbor (PayPal). */
    private const PRESETS = ['mercadopago' => 'plaza', 'stripe' => 'vector', 'paypal' => 'harbor'];

    /** @param array<string, mixed> $config */
    private function gateway(string $driver, array $config, string $webhookSecret): \Ronu\PaymentGateways\Contracts\Gateway
    {
        $credentials = match ($driver) {
            'stripe' => ['secret_key' => $config['access_token']],
            'paypal' => ['client_id' => $config['client_id'], 'client_secret' => $config['access_token']],
            default => ['access_token' => $config['access_token']],
        };
        // PayPal does not sign with a shared secret: Harbor verifies against the app's webhook id.
        $webhooks = $driver === 'paypal' ? ['id' => $webhookSecret] : ['secret' => $webhookSecret, 'tolerance_seconds' => 600];
        return GatewayFactory::make(self::PRESETS[$driver], [
            'credentials' => $credentials,
            'webhooks' => $webhooks,
            'emulator' => ['api_url' => rtrim($config['api_url'], '/'), 'checkout_url' => rtrim($config['checkout_url'], '/'), 'scenario' => ''],
            'http' => ['timeout' => 10, 'connect_timeout' => 3, 'retries' => 0],
        ]);
    }

    /**
     * Webhook secret rotation: the previous secret stays valid until an absolute instant, never open
     * ended and never longer than MAX_ROTATION_SECONDS ahead. An unset secret means no rotation; a
     * secret without a valid end, an end too far away, or a secret equal to the current one makes the
     * account not ready rather than silently accepting either secret forever.
     *
     * @param array<string, mixed> $config
     * @return array{0: ?\Ronu\PaymentGateways\Contracts\Gateway, 1: ?int}
     */
    private function previousWebhookSecret(array $config, string $driver = 'mercadopago'): array
    {
        $secret = trim((string) ($config['webhook_secret_previous'] ?? ''));
        if ($secret === '') {
            return [null, null];
        }
        $until = strtotime((string) ($config['webhook_secret_previous_until'] ?? ''));
        if ($until === false || $secret === $config['webhook_secret'] || $until - time() > self::MAX_ROTATION_SECONDS) {
            throw new PaymentError('invalid_webhook_rotation', 503);
        }
        return [$this->gateway($driver, $config, $secret), $until];
    }
}
