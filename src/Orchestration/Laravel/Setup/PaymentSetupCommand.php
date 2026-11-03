<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration\Laravel\Setup;

use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Foundation\Application;
use Ronu\AppContext\Contracts\ClientRepositoryInterface;
use Ronu\PaymentGateways\Orchestration\Laravel\ConfiguredAccounts;
use Ronu\PaymentGateways\Orchestration\Laravel\PaymentRuntime;
use Ronu\PaymentGateways\Orchestration\PaymentError;

final class PaymentSetupCommand extends Command
{
    protected $signature = 'payments:setup {--emulator= : Origen HTTP del emulador local} {--emulator-checkout=http://localhost:3101} {--emulator-token-file=} {--partner=} {--tenant=} {--frontend-env=} {--dry-run} {--json}';
    protected $description = 'Prepara pagos y partner de forma idempotente; solo abre ventas con emulador local explícito';

    public function handle(PaymentRuntime $runtime, DatabaseManager $database): int
    {
        $report = new SetupReport();
        $lock = null;
        try {
            $app = $this->getLaravel();
            if (!$app instanceof Application) {
                throw new SetupFailure('laravel_application_required');
            }
            $emulatorRequested = $this->input->hasParameterOption('--emulator');
            if ($emulatorRequested && $app->environment('production')) {
                throw new SetupFailure('emulator_forbidden_in_production');
            }
            $dryRun = (bool) $this->option('dry-run');
            if ($app->configurationIsCached() && !$dryRun) {
                throw new SetupFailure('config_cached_clear_before_setup');
            }
            $config = $app['config']->get('payments', []);
            $project = (string) ($config['project'] ?? '');
            $accounts = $config['accounts'] ?? [];
            if ($project === '' || !is_array($accounts) || $accounts === [] || !isset($accounts[$config['default_account'] ?? ''])) {
                throw new SetupFailure('invalid_accounts_configuration');
            }
            $baseUrl = rtrim((string) $app['config']->get('app.url', ''), '/');
            if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || !in_array(parse_url($baseUrl, PHP_URL_SCHEME), ['http', 'https'], true)
                || parse_url($baseUrl, PHP_URL_USER) !== null || parse_url($baseUrl, PHP_URL_QUERY) !== null || parse_url($baseUrl, PHP_URL_FRAGMENT) !== null) {
                throw new SetupFailure('invalid_app_url');
            }
            $db = $database->connection($config['connection'] ?? null);
            $tables = $config['tables'] ?? [];
            if (!isset($tables['intents']) || $tables === []) {
                throw new SetupFailure('payment_tables_not_configured');
            }
            foreach ($tables as $key => $table) {
                $tables[$key] = $this->tableName($table);
                if (!$db->getSchemaBuilder()->hasTable($tables[$key])) {
                    throw new SetupFailure('payment_tables_not_migrated');
                }
            }
            $config['tables'] = $tables;
            if (!$runtime->adapter()->ready()) {
                throw new SetupFailure('adapter_not_ready');
            }
            $scheduled = false;
            foreach ($app->make(Schedule::class)->events() as $event) {
                if (str_contains($event->command ?? '', 'payments:run work')) {
                    $scheduled = true;
                }
            }
            if (!$scheduled || !($config['recovery_enabled'] ?? false)) {
                throw new SetupFailure('payment_scheduler_not_declared');
            }
            foreach ($accounts as $alias => $account) {
                if (!preg_match('/^[a-z][a-z0-9_-]{0,62}$/D', $alias)) {
                    throw new SetupFailure('invalid_account_alias');
                }
                $route = $app['router']->getRoutes()->match(Request::create($baseUrl.'/client/payments/webhooks/'.$alias, 'POST'));
                if (!str_ends_with($route->getActionName(), 'PaymentController@webhook')) {
                    throw new SetupFailure('payment_webhook_not_registered');
                }
                // Valida la estructura e identidad sin necesitar credenciales todavía.
                $this->identity($alias, $account);
                $this->guardHistory($database, $config, $alias, $account);
            }
            $report->add('ready', 'unchanged');
            $storeClass = $config['setup']['value_store'] ?? null;
            $store = $storeClass === null ? new EnvFile($app->environmentFilePath()) : $app->make($storeClass);
            if (!$store instanceof ValueStore) {
                throw new SetupFailure('invalid_setup_value_store');
            }
            // Una aplicación Floci nunca cae silenciosamente en escritura de secretos en .env.
            if ($app['config']->has('floci') && $storeClass === null) {
                throw new SetupFailure('floci_setup_value_store_required');
            }
            $store->read();
            $code = $this->text('partner') ?: preg_replace('/[^A-Za-z0-9_-]/', '-', $project).'-payments';
            $tenant = $this->text('tenant') ?: $project;
            $frontendPath = $this->text('frontend-env');
            $frontend = $frontendPath === '' ? null : new EnvFile($frontendPath);
            $frontValues = $frontend?->read() ?? [];
            if ($frontend !== null && (!is_dir(dirname($frontendPath)) || !is_writable(dirname($frontendPath)))) {
                throw new SetupFailure('frontend_env_not_writable');
            }
            $clients = $app->make(ClientRepositoryInterface::class);
            if ($this->option('json') && $frontend === null && $clients->findByAppCode($code) === null && !$dryRun) {
                throw new SetupFailure('frontend_env_required_for_new_partner_json');
            }
            if (!$dryRun) {
                $directory = $app->storagePath('app/payments');
                if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
                    throw new SetupFailure('setup_directory_failed');
                }
                $lock = fopen($directory.'/setup.lock', 'c');
                if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
                    throw new SetupFailure('setup_already_running');
                }
            }
            $allReady = false;
            if ($emulatorRequested) {
                $api = EmulatorProvisioner::origin($this->text('emulator') ?: 'http://localhost:8090');
                $checkout = EmulatorProvisioner::origin($this->text('emulator-checkout'));
                $tokenFile = $this->text('emulator-token-file');
                if ($tokenFile !== '' && (!is_file($tokenFile) || !is_readable($tokenFile))) {
                    throw new SetupFailure('emulator_token_file_unreadable');
                }
                $token = $tokenFile !== '' ? trim((string) file_get_contents($tokenFile)) : trim((string) (getenv('PAYMENTS_EMULATOR_ADMIN_TOKEN') ?: ''));
                if ($token === '') {
                    $report->add('emulator', 'skipped', 'emulator_admin_token_missing');
                } else {
                    $http = new Client(['base_uri' => $api, 'headers' => ['Authorization' => 'Bearer '.$token], 'timeout' => 10, 'connect_timeout' => 3]);
                    $provisioner = $app->bound(EmulatorProvisioner::class) ? $app->make(EmulatorProvisioner::class) : new EmulatorProvisioner($http);
                    $allReady = true;
                    foreach ($accounts as $alias => $account) {
                        try {
                            $mapping = $config['setup']['accounts'][$alias] ?? [];
                            foreach (['merchant_id', 'access_token', 'webhook_secret', 'api_url', 'checkout_url', ...(($account['driver'] ?? 'mercadopago') === 'paypal' ? ['client_id'] : [])] as $field) {
                                if (!isset($mapping[$field]) || !preg_match('/^[A-Z][A-Z0-9_]*$/D', $mapping[$field])) {
                                    throw new SetupFailure('setup_account_env_mapping_missing');
                                }
                            }
                            $persisted = $store->read();
                            $current = [];
                            foreach ($mapping as $field => $env) {
                                $current[$field] = $persisted[$env] ?? (string) ($account[$field] ?? '');
                            }
                            $history = $db->table($tables['intents'])->where('data->account_alias', $alias)->exists();
                            $result = $provisioner->provision(
                                $project.'-'.$alias,
                                $account['driver'] ?? 'mercadopago',
                                $baseUrl.'/client/payments/webhooks/'.$alias,
                                $current,
                                $history,
                                fn (string $merchant) => $this->guardHistory($database, $config, $alias, array_replace($account, ['merchant_id' => $merchant])),
                                $dryRun,
                                $config['setup']['applications'][$alias] ?? null
                            );
                            if (!$dryRun) {
                                $fields = array_replace($result['values'], ['api_url' => $api, 'checkout_url' => $checkout]);
                                $values = [];
                                foreach ($fields as $field => $value) {
                                    if (isset($mapping[$field])) {
                                        $values[$mapping[$field]] = $value;
                                    }
                                }
                                $envStatus = $store->write($values);
                                $report->add('env:'.$alias, $envStatus);
                                $account = array_replace($account, $fields);
                                (new ConfiguredAccounts(array_replace($config, ['accounts' => [$alias => $account]])))->account($alias);
                            }
                            $report->add('emulator:'.$alias, $result['status'], $dryRun ? 'dry_run' : '');
                        } catch (SetupFailure $error) {
                            $allReady = false;
                            $report->add('emulator:'.$alias, $error->getMessage() === 'emulator_unavailable' ? 'skipped' : 'failed', $error->getMessage());
                        }
                    }
                    try {
                        if (!$provisioner->checkoutReady($checkout)) {
                            throw new SetupFailure('checkout_unavailable_build_required');
                        }
                        $report->add('checkout', 'unchanged');
                    } catch (\Throwable) {
                        $allReady = false;
                        $report->add('checkout', 'skipped', 'checkout_unavailable_build_required');
                    }
                    $report->add('webhook', 'skipped', 'Verificar acceso desde el worker a '.$baseUrl.'/client/payments/webhooks/<cuenta>; el scheduler debe estar ejecutándose.');
                }
            } else {
                $report->add('emulator', 'skipped', 'Sin --emulator: transporte real de orquestación todavía no disponible; comprobar credenciales por cuenta.');
            }
            $partner = (new PartnerClientProvisioner($clients, $database, $app['config']->get('app-context.client_repository', []), $app->storagePath('app/payments/partner-clients.php')))
                ->provision($code, $tenant, PartnerClientProvisioner::capabilities(array_keys($accounts), (bool) ($config['refunds_enabled'] ?? false)), $dryRun);
            $report->add('partner', $partner['status'], $dryRun ? 'dry_run' : '');
            $frontMapping = array_replace(['base_url' => 'NUXT_PAYMENTS_API_BASE_URL', 'client_id' => 'NUXT_PAYMENTS_CLIENT_ID', 'api_key' => 'NUXT_PAYMENTS_API_KEY', 'session_secret' => 'NUXT_PAYMENTS_SESSION_SECRET', 'local_actions' => 'NUXT_PAYMENTS_LOCAL_ACTIONS'], $config['setup']['frontend'] ?? []);
            if ($frontend !== null && !$dryRun) {
                $values = [$frontMapping['base_url'] => $baseUrl, $frontMapping['client_id'] => $code];
                if (strlen($frontValues[$frontMapping['session_secret']] ?? '') < 32) {
                    $values[$frontMapping['session_secret']] = bin2hex(random_bytes(32));
                }
                if ($partner['key'] !== null) {
                    $values[$frontMapping['api_key']] = $partner['key'];
                } else {
                    $client = $clients->findByAppCode($code);
                    $key = $frontValues[$frontMapping['api_key']] ?? '';
                    if ($client === null || $key === '' || !$clients->verifyKeyHash($key, $client->keyHash)) {
                        $report->add('frontend-key', 'failed', 'partner_key_missing_or_mismatched_no_rotation');
                    }
                }
                if ($emulatorRequested) {
                    $values[$frontMapping['local_actions']] = 'true';
                }
                $report->add('frontend', $frontend->write($values));
            } else {
                $report->add('frontend', 'skipped', $dryRun ? 'dry_run' : 'Indicar --frontend-env para configurar el front automáticamente.');
                if ($partner['key'] !== null && !$this->option('json')) {
                    $this->line($frontMapping['api_key'].'='.$partner['key']);
                    $this->line($frontMapping['client_id'].'='.$code);
                    $this->line($frontMapping['base_url'].'='.$baseUrl);
                }
            }
            if ($emulatorRequested && $allReady && !$report->failed() && !$dryRun) {
                $report->add('sales', $store->write(['PAYMENTS_ACCEPT_NEW' => 'true']));
            } else {
                $report->add('sales', 'skipped', $dryRun ? 'dry_run' : 'Ventas sin modificar; apertura requiere emulador y todas las comprobaciones correctas.');
            }
        } catch (SetupFailure $error) {
            $report->add('setup', 'failed', $error->getMessage());
        } catch (PaymentError $error) {
            $report->add('setup', 'failed', $error->reason);
        } catch (\Throwable) {
            $report->add('setup', 'failed', 'setup_unavailable_check_configuration');
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
        if ($this->option('json')) {
            $this->line(json_encode(['steps' => $report->steps(), 'ok' => !$report->failed()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            foreach ($report->steps() as $step) {
                $this->line($step['step'].': '.$step['status'].($step['reason'] === '' ? '' : ' ('.$step['reason'].')'));
            }
        }
        return $report->failed() ? self::FAILURE : self::SUCCESS;
    }

    private function text(string $name): string
    {
        $value = $this->option($name);
        return is_string($value) ? $value : '';
    }

    /** Laravel queries use qualified names; the runtime configuration keeps its structured mapping. */
    private function tableName(mixed $table): string
    {
        if (is_array($table)) {
            foreach (['schema', 'table'] as $part) {
                if (!isset($table[$part]) || !is_string($table[$part]) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/D', $table[$part])) {
                    throw new SetupFailure('invalid_storage_mapping');
                }
            }
            return $table['schema'].'.'.$table['table'];
        }
        if (!is_string($table) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}(\.[A-Za-z_][A-Za-z0-9_]{0,62})?$/D', $table)) {
            throw new SetupFailure('invalid_storage_mapping');
        }
        return $table;
    }

    /** @param array<string,mixed> $account */
    private function identity(string $alias, array $account): string
    {
        $probe = array_replace($account, ['access_token' => 'setup-validation', 'client_id' => 'setup-validation', 'webhook_secret' => 'setup-validation', 'webhook_secret_previous' => '', 'api_url' => 'http://127.0.0.1', 'checkout_url' => 'http://127.0.0.1']);
        return (new ConfiguredAccounts(['accounts' => [$alias => $probe]]))->account($alias)->identity();
    }

    /**
     * @param array<string,mixed> $configuration
     * @param array<string,mixed> $account
     */
    private function guardHistory(DatabaseManager $database, array $configuration, string $alias, array $account): void
    {
        $identity = $this->identity($alias, $account);
        $query = $database->connection($configuration['connection'] ?? null)->table($configuration['tables']['intents'])->where('data->account_alias', $alias);
        if ($query->where(static fn ($q) => $q->where('data->account_identity', '<>', $identity)->orWhereNull('data->account_identity'))->exists()) {
            throw new SetupFailure('account_identity_changed:'.$alias);
        }
    }
}
