<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Config;

use Ronu\PaymentGateways\Exceptions\ConfigurationException;

/**
 * One gateway's settings, validated when the gateway is built so a typo fails
 * at boot, not on the first payment.
 *
 *     [
 *         'driver'      => 'mercadopago',          // mercadopago | stripe | paypal | any registered driver
 *         'environment' => 'emulator',             // emulator | sandbox | live
 *         'credentials' => ['access_token' => '…'],
 *         'webhooks'    => ['secret' => '…'],
 *         'options'     => ['currency' => 'ARS'],  // driver behaviour, see each driver
 *         'emulator'    => ['api_url' => 'http://localhost:8080', 'checkout_url' => '…', 'scenario' => '…'],
 *         'http'        => ['timeout' => 15, 'connect_timeout' => 5, 'retries' => 2, 'retry_delay_ms' => 250],
 *         'base_url'    => null,                   // overrides the environment's host (proxies, mocks)
 *     ]
 */
final readonly class GatewayConfig
{
    public const SECTIONS = ['driver', 'environment', 'base_url', 'credentials', 'webhooks', 'options', 'emulator', 'http'];

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $webhooks
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $emulator
     * @param  array<string, mixed>  $http
     */
    public function __construct(
        public string $name,
        public string $driver,
        public Environment $environment,
        public ?string $baseUrl = null,
        public array $credentials = [],
        public array $webhooks = [],
        public array $options = [],
        public array $emulator = [],
        public array $http = [],
    ) {
        if ($environment === Environment::Emulator && $this->emulatorApiUrl() === '' && $baseUrl === null) {
            throw new ConfigurationException("Payment gateway '{$name}': emulator.api_url is required with environment 'emulator'.");
        }
    }

    /** @param  array<string, mixed>  $config */
    public static function fromArray(string $name, array $config): self
    {
        $unknown = array_diff(array_keys($config), self::SECTIONS);
        if ($unknown !== []) {
            throw new ConfigurationException(sprintf("Payment gateway '%s': unknown key(s) %s. Expected: %s.", $name, implode(', ', $unknown), implode(', ', self::SECTIONS)));
        }

        $driver = $config['driver'] ?? null;
        if (! is_string($driver) || $driver === '') {
            throw new ConfigurationException("Payment gateway '{$name}': 'driver' is required (mercadopago, stripe, paypal or a registered driver).");
        }

        $environment = Environment::tryFrom((string) ($config['environment'] ?? ''))
            ?? throw new ConfigurationException("Payment gateway '{$name}': 'environment' must be emulator, sandbox or live.");

        $sections = [];
        foreach (['credentials', 'webhooks', 'options', 'emulator', 'http'] as $section) {
            $value = $config[$section] ?? [];
            if (! is_array($value)) {
                throw new ConfigurationException("Payment gateway '{$name}': '{$section}' must be an array.");
            }
            $sections[$section] = $value;
        }

        $baseUrl = $config['base_url'] ?? null;

        return new self($name, $driver, $environment, is_string($baseUrl) && $baseUrl !== '' ? $baseUrl : null, ...$sections);
    }

    /** A credential the driver cannot work without. */
    public function credential(string $key): string
    {
        return $this->credentialOrNull($key)
            ?? throw new ConfigurationException("Payment gateway '{$this->name}': credentials.{$key} is required.");
    }

    public function credentialOrNull(string $key): ?string
    {
        $value = $this->credentials[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    public function webhook(string $key, mixed $default = null): mixed
    {
        return $this->webhooks[$key] ?? $default;
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    public function isEmulator(): bool
    {
        return $this->environment === Environment::Emulator;
    }

    public function emulatorApiUrl(): string
    {
        return rtrim((string) ($this->emulator['api_url'] ?? ''), '/');
    }

    /**
     * The emulator URL without a trailing `/{profile}`: `http://localhost:8090` and
     * `http://localhost:8090/plaza` both name the same emulator.
     */
    public function emulatorRoot(string $profile): string
    {
        return self::withoutProfile($this->emulatorApiUrl(), $profile);
    }

    /** Where the emulator serves its buyer pages, when it is not the API host. Same trailing-profile rule. */
    public function emulatorCheckoutUrl(?string $profile = null): ?string
    {
        $url = rtrim((string) ($this->emulator['checkout_url'] ?? ''), '/');
        $url = $profile !== null ? self::withoutProfile($url, $profile) : $url;

        return $url !== '' ? $url : null;
    }

    private static function withoutProfile(string $url, string $profile): string
    {
        return str_ends_with($url, '/' . $profile) ? substr($url, 0, -strlen($profile) - 1) : $url;
    }

    /**
     * Headers only the emulator understands, sent when a payment is created.
     *
     * @return array<string, string>
     */
    public function emulatorHeaders(): array
    {
        $scenario = (string) ($this->emulator['scenario'] ?? '');

        return $this->isEmulator() && $scenario !== '' ? ['X-Emulator-Scenario' => $scenario] : [];
    }

    /**
     * @param  array<string, string>  $hosts  environment value => base URL ('sandbox', 'live')
     * @param  string  $emulatorProfile  The emulator path that serves this provider (`plaza`, `vector`, `harbor`).
     */
    public function resolveBaseUrl(array $hosts, string $emulatorProfile): string
    {
        if ($this->baseUrl !== null) {
            return rtrim($this->baseUrl, '/');
        }
        if ($this->isEmulator()) {
            return $this->emulatorRoot($emulatorProfile) . '/' . $emulatorProfile;
        }

        return $hosts[$this->environment->value]
            ?? throw new ConfigurationException("Payment gateway '{$this->name}': driver '{$this->driver}' has no {$this->environment->value} host; set base_url.");
    }

    public function timeout(): float
    {
        return (float) ($this->http['timeout'] ?? 15);
    }

    public function connectTimeout(): float
    {
        return (float) ($this->http['connect_timeout'] ?? 5);
    }

    /** Extra attempts for 408, 429, 5xx and network failures. Writes need an idempotency key. */
    public function retries(): int
    {
        return max(0, (int) ($this->http['retries'] ?? 2));
    }

    public function retryDelayMs(): int
    {
        return max(0, (int) ($this->http['retry_delay_ms'] ?? 250));
    }

    /** A longer Retry-After fails the call (retryable) instead of holding the request. */
    public function maxRetryDelayMs(): int
    {
        return max(0, (int) ($this->http['max_retry_delay_ms'] ?? 5_000));
    }
}
