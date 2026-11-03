<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration\Laravel\Setup;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Ronu\AppContext\Contracts\ClientRepositoryInterface;

/** Actualiza únicamente el partner seleccionado; conserva claves y restricciones de acceso. */
final class PartnerClientProvisioner
{
    /** @param array<string,mixed> $configuration */
    public function __construct(private readonly ClientRepositoryInterface $clients, private readonly DatabaseManager $database, private readonly array $configuration, private readonly string $localFile)
    {
    }

    /**
     * @param list<string> $accounts
     * @return list<string>
     */
    public static function capabilities(array $accounts, bool $refunds): array
    {
        $capabilities = ['partner:payments:create', 'partner:payments:read'];
        if ($refunds) {
            $capabilities[] = 'partner:payments:refund';
        }
        foreach ($accounts as $account) {
            $capabilities[] = 'partner:payments:account:'.$account;
        }
        sort($capabilities);
        return array_values(array_unique($capabilities));
    }

    /**
     * @param list<string> $capabilities
     * @return array{status:string,key:?string}
     */
    public function provision(string $code, string $tenant, array $capabilities, bool $dryRun): array
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/D', $code) || trim($tenant) === '') {
            throw new SetupFailure('invalid_partner_identity');
        }
        return match ($this->configuration['driver'] ?? 'config') {
            'config' => $this->config($code, $tenant, $capabilities, $dryRun),
            'eloquent' => $this->eloquent($code, $tenant, $capabilities, $dryRun),
            default => throw new SetupFailure('unsupported_client_driver'),
        };
    }

    /**
     * @param list<string> $capabilities
     * @return array{status:string,key:?string}
     */
    private function config(string $code, string $tenant, array $capabilities, bool $dryRun): array
    {
        if (($this->configuration['config']['hash_algorithm'] ?? 'bcrypt') === 'plain') {
            throw new SetupFailure('plaintext_client_hash_refused');
        }
        $local = is_file($this->localFile) ? require $this->localFile : [];
        if (!is_array($local)) {
            throw new SetupFailure('invalid_local_clients');
        }
        $existing = $local[$code] ?? $this->configuration['config']['clients'][$code] ?? null;
        if ($existing !== null && (!($existing['is_active'] ?? true) || ($existing['is_revoked'] ?? false)
            || (!empty($existing['expires_at']) && strtotime($existing['expires_at']) <= time())
            || ($existing['channel'] ?? 'partner') !== 'partner')) {
            throw new SetupFailure('partner_inactive_or_wrong_channel');
        }
        $client = array_replace($existing ?? [], ['name' => $existing['name'] ?? $code, 'channel' => 'partner', 'tenant_id' => $tenant, 'capabilities' => $capabilities, 'is_active' => true]);
        if ($existing === $client && isset($local[$code])) {
            return ['status' => 'unchanged', 'key' => null];
        }
        if ($dryRun) {
            return ['status' => 'skipped', 'key' => null];
        }
        $key = null;
        if ($existing === null) {
            $issued = $this->clients->generateKey();
            $key = $issued['key'];
            $client['key_hash'] = $issued['hash'];
            $client['key_prefix'] = $issued['prefix'];
        } elseif (empty($client['key_hash'])) {
            throw new SetupFailure('partner_hash_missing');
        }
        $local[$code] = $client;
        $directory = dirname($this->localFile);
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
            throw new SetupFailure('local_clients_directory_failed');
        }
        if (!is_file($directory.'/.gitignore')) {
            EnvFile::privateWrite($directory.'/.gitignore', "*\n!.gitignore\n");
        }
        $temp = $this->localFile.'.'.bin2hex(random_bytes(8));
        EnvFile::privateWrite($temp, "<?php\n\nreturn ".var_export($local, true).";\n");
        if (!rename($temp, $this->localFile)) {
            throw new SetupFailure('local_clients_write_failed');
        }
        return ['status' => $existing === null ? 'created' : 'updated', 'key' => $key];
    }

    /** @return array{Connection,string,string} */
    private function tables(): array
    {
        $settings = $this->configuration['eloquent'] ?? [];
        $connection = $settings['connection'] ?? null;
        $names = [];
        foreach (['app' => 'apps_table', 'app_key' => 'app_keys_table'] as $kind => $key) {
            $modelClass = $settings[$kind.'_model'] ?? null;
            if (is_string($modelClass) && is_subclass_of($modelClass, Model::class)) {
                $model = new $modelClass();
                $modelConnection = $model->getConnectionName();
                if ($connection !== null && $modelConnection !== null && $connection !== $modelConnection) {
                    throw new SetupFailure('partner_cross_connection_refused');
                }
                $connection ??= $modelConnection;
                $names[] = $model->getTable();
            } else {
                $names[] = $settings[$key] ?? ($kind === 'app' ? 'api_apps' : 'api_app_keys');
            }
        }
        $db = $this->database->connection($connection);
        foreach ($names as $name) {
            if (!$db->getSchemaBuilder()->hasTable($name)) {
                throw new SetupFailure('partner_tables_not_migrated');
            }
        }
        return [$db, $names[0], $names[1]];
    }

    /**
     * @param list<string> $capabilities
     * @return array{status:string,key:?string}
     */
    private function eloquent(string $code, string $tenant, array $capabilities, bool $dryRun): array
    {
        [$db, $apps, $keys] = $this->tables();
        return $db->transaction(function () use ($db, $apps, $keys, $code, $tenant, $capabilities, $dryRun): array {
            $app = $db->table($apps)->where('app_code', $code)->lockForUpdate()->first();
            if ($app === null) {
                if ($dryRun) {
                    return ['status' => 'skipped', 'key' => null];
                }
                $client = $this->clients->create(['app_code' => $code, 'name' => $code, 'channel' => 'partner', 'tenant_id' => $tenant, 'capabilities' => $capabilities]);
                $key = $client->metadata['generated_key'] ?? null;
                if (!is_string($key) || $key === '') {
                    throw new SetupFailure('partner_key_not_returned');
                }
                return ['status' => 'created', 'key' => $key];
            }
            $config = $this->decode($app->config ?? null);
            if (!$app->is_active || ($app->deleted_at ?? null) !== null || ($config['channel'] ?? 'partner') !== 'partner') {
                throw new SetupFailure('partner_inactive_or_wrong_channel');
            }
            $records = $db->table($keys)->where('app_id', $app->id)->whereNull('revoked_at')->get();
            $active = $records->filter(static fn (object $key): bool => empty($key->expires_at) || strtotime($key->expires_at) > time());
            if ($active->isEmpty()) {
                throw new SetupFailure('partner_key_revoked_or_expired');
            }
            $nextConfig = array_replace($config, ['channel' => 'partner', 'tenant_id' => $tenant]);
            $changed = $config !== $nextConfig;
            if ($changed && !$dryRun) {
                $db->table($apps)->where('id', $app->id)->update(['config' => json_encode($nextConfig, JSON_THROW_ON_ERROR)]);
            }
            foreach ($active as $key) {
                $oldConfig = $this->decode($key->config ?? null);
                $newConfig = array_replace($oldConfig, ['capabilities' => $capabilities]);
                $scopes = implode(' ', $capabilities);
                if ($oldConfig !== $newConfig || $key->scopes !== $scopes) {
                    $changed = true;
                    if (!$dryRun) {
                        $db->table($keys)->where('id', $key->id)->update(['config' => json_encode($newConfig, JSON_THROW_ON_ERROR), 'scopes' => $scopes]);
                    }
                }
            }
            return ['status' => $changed ? ($dryRun ? 'skipped' : 'updated') : 'unchanged', 'key' => null];
        });
    }

    /** @return array<string,mixed> */
    private function decode(mixed $value): array
    {
        $decoded = is_array($value) ? $value : json_decode((string) ($value ?? '{}'), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new SetupFailure('invalid_partner_config');
        }
        return $decoded;
    }
}
