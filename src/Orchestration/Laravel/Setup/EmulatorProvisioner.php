<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration\Laravel\Setup;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/** Solo control plane local; no crea pagos ni rota credenciales. */
final class EmulatorProvisioner
{
    public function __construct(private readonly ClientInterface $http, private readonly ?ClientInterface $checkoutHttp = null)
    {
    }

    public function checkoutReady(string $origin): bool
    {
        self::origin($origin);
        try {
            // El token de administración no se envía jamás al checkout.
            $client = $this->checkoutHttp ?? new Client();
            return $client->request('GET', $origin, ['timeout' => 3, 'connect_timeout' => 2, 'allow_redirects' => false, 'http_errors' => false])->getStatusCode() === 200;
        } catch (GuzzleException) {
            return false;
        }
    }

    public static function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'http'
            || !in_array($parts['host'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new SetupFailure('unsafe_emulator_endpoint');
        }
        return rtrim($url, '/');
    }

    /**
     * @param array<string,string> $current Campos de cuenta, no nombres de variables.
     * @param callable(string):void $guard Rechaza cambios de identidad antes de mutar.
     * @return array{status:string,values:array<string,string>,application:string}
     */
    public function provision(string $name, string $driver, string $webhook, array $current, bool $hasHistory, callable $guard, bool $dryRun = false, ?string $applicationId = null): array
    {
        $provider = ['mercadopago' => 'plaza', 'stripe' => 'vector', 'paypal' => 'harbor'][$driver] ?? throw new SetupFailure('unsupported_account');
        $application = $applicationId !== null ? $this->request('GET', '/applications/'.rawurlencode($applicationId)) : $this->find('/applications', $name, 'name');
        if ($application !== null) {
            if (($application['provider'] ?? null) !== $provider || ($application['status'] ?? '') !== 'active'
                || (!empty($application['expiresAt']) && strtotime($application['expiresAt']) <= time())) {
                throw new SetupFailure('emulator_application_conflict');
            }
            $merchant = (string) ($application['bankingProfile']['providerMerchantId'] ?? $application['ownerId'] ?? '');
            if ($merchant === '') {
                throw new SetupFailure('emulator_merchant_missing');
            }
            $guard($merchant);
            foreach (['access_token', 'webhook_secret', ...($driver === 'paypal' ? ['client_id'] : [])] as $field) {
                if (($current[$field] ?? '') === '') {
                    throw new SetupFailure('emulator_credentials_missing_no_rotation');
                }
            }
            $changed = ($application['webhookUrl'] ?? '') !== $webhook;
            if ($changed && !$dryRun) {
                $this->request('PATCH', '/applications/'.rawurlencode($application['id']), ['webhookUrl' => $webhook]);
            }
            return ['status' => $changed ? 'updated' : 'unchanged', 'values' => array_replace($current, ['merchant_id' => $merchant]), 'application' => $application['id']];
        }
        if ($hasHistory) {
            throw new SetupFailure('historical_account_requires_existing_application');
        }
        if ($dryRun) {
            return ['status' => 'skipped', 'values' => [], 'application' => ''];
        }
        $email = 'payments-'.substr(hash('sha256', $name), 0, 32).'@example.test';
        $merchant = $this->find('/users', $email, 'email');
        if ($merchant === null) {
            $merchant = $this->request('POST', '/users', ['email' => $email, 'displayName' => $name, 'role' => 'merchant', 'password' => bin2hex(random_bytes(24))]);
        }
        if (($merchant['role'] ?? '') !== 'merchant' || empty($merchant['id'])) {
            throw new SetupFailure('emulator_merchant_conflict');
        }
        $guard($merchant['id']);
        $secret = bin2hex(random_bytes(32));
        $application = $this->request('POST', '/applications', ['name' => $name, 'provider' => $provider, 'ownerId' => $merchant['id'], 'webhookUrl' => $webhook, 'webhookSecret' => $secret]);
        $credentials = $application['credentials'] ?? [];
        $values = ['merchant_id' => $merchant['id'], 'access_token' => (string) ($credentials[$driver === 'paypal' ? 'clientSecret' : 'accessToken'] ?? ''), 'webhook_secret' => $secret];
        if ($driver === 'paypal') {
            $values['client_id'] = (string) ($credentials['clientId'] ?? '');
        }
        if (in_array('', $values, true) || empty($application['id'])) {
            throw new SetupFailure('emulator_invalid_credentials');
        }
        return ['status' => 'created', 'values' => $values, 'application' => $application['id']];
    }

    /** @return array<string,mixed>|null */
    private function find(string $endpoint, string $name, string $field): ?array
    {
        $matches = [];
        $page = 1;
        do {
            $result = $this->request('GET', $endpoint.'?'.http_build_query(['search' => $name, 'page' => $page, 'pageSize' => 200]));
            if (!is_array($result['data'] ?? null) || !isset($result['meta']['lastPage'])) {
                throw new SetupFailure('emulator_invalid_listing');
            }
            foreach ($result['data'] as $row) {
                if (($row[$field] ?? null) === $name) {
                    $matches[] = $row;
                }
            }
            if ($page >= 1000 && $page < $result['meta']['lastPage']) {
                throw new SetupFailure('emulator_listing_limit');
            }
        } while (++$page <= $result['meta']['lastPage']);
        if (count($matches) > 1) {
            throw new SetupFailure('emulator_duplicate_name');
        }
        return $matches[0] ?? null;
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        try {
            $response = $this->http->request($method, $path, ['allow_redirects' => false, 'http_errors' => false, ...($body === null ? [] : ['json' => $body])]);
        } catch (GuzzleException) {
            throw new SetupFailure('emulator_unavailable');
        }
        if (in_array($response->getStatusCode(), [401, 403], true)) {
            throw new SetupFailure('emulator_unauthorized');
        }
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new SetupFailure('emulator_http_error');
        }
        try {
            $data = json_decode((string) $response->getBody(), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new SetupFailure('emulator_invalid_response');
        }
        if (!is_array($data)) {
            throw new SetupFailure('emulator_invalid_response');
        }
        return $data;
    }
}
