<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Config\Repository;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\DatabaseManager;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Hashing\HashManager;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Ronu\AppContext\Contracts\ClientRepositoryInterface;
use Ronu\AppContext\Repositories\ConfigClientRepository;
use Ronu\AppContext\Repositories\EloquentClientRepository;
use Ronu\PaymentGateways\Orchestration\Laravel\ConfiguredAccounts;
use Ronu\PaymentGateways\Orchestration\Laravel\PaymentController;
use Ronu\PaymentGateways\Orchestration\Laravel\PaymentRuntime;
use Ronu\PaymentGateways\Orchestration\Laravel\ProductAdapter;
use Ronu\PaymentGateways\Orchestration\Laravel\Setup\EmulatorProvisioner;
use Ronu\PaymentGateways\Orchestration\Laravel\Setup\EnvFile;
use Ronu\PaymentGateways\Orchestration\Laravel\Setup\PartnerClientProvisioner;
use Ronu\PaymentGateways\Orchestration\Laravel\Setup\PaymentSetupCommand;
use Ronu\PaymentGateways\Orchestration\Laravel\Setup\SetupFailure;
use Symfony\Component\Console\Tester\CommandTester;

final class PaymentSetupTest extends TestCase
{
    private string $root;
    private Application $app;
    private Capsule $capsule;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/payment-setup-test-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        mkdir($this->root.'/storage', 0700);
        $this->app = new Application($this->root);
        $this->app->instance('env', 'testing');
        $this->app->instance('config', new Repository(['app' => ['url' => 'http://localhost:8000'], 'hashing' => ['driver' => 'bcrypt', 'bcrypt' => ['rounds' => 4]], 'app-context' => ['client_repository' => ['driver' => 'config', 'config' => ['clients' => [], 'hash_algorithm' => 'bcrypt']]]]));
        $this->app->instance('hash', new HashManager($this->app));
        $events = new Dispatcher($this->app);
        $this->app->instance('events', $events);
        $this->app['config']->set('cache', ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]]);
        $this->app->register(\Illuminate\Cache\CacheServiceProvider::class);
        $router = new Router($events, $this->app);
        $router->post('client/payments/webhooks/mp', [PaymentController::class, 'webhook']);
        $this->app->instance('router', $router);
        $this->capsule = new Capsule($this->app);
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $manager = $this->capsule->getDatabaseManager();
        $this->app->instance(DatabaseManager::class, $manager);
        $this->app->instance('db', $manager);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $manager->connection()->getSchemaBuilder()->create('intents', function ($table): void {
            $table->increments('id');
            $table->text('data');
        });
        $adapter = $this->createMock(ProductAdapter::class);
        $adapter->method('ready')->willReturn(true);
        $this->app->instance(get_class($adapter), $adapter);
        $schedule = new Schedule();
        $schedule->exec('php artisan payments:run work --limit=200')->everyMinute();
        $this->app->instance(Schedule::class, $schedule);
        $this->app['config']->set('payments', ['project' => 'demo', 'adapter' => get_class($adapter), 'recovery_enabled' => true, 'default_account' => 'mp', 'accounts' => ['mp' => $this->account()], 'tables' => ['intents' => 'intents']]);
        file_put_contents($this->root.'/.env', "APP_ENV=testing\nPAYMENTS_ACCEPT_NEW=false\n");
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        $this->capsule->getDatabaseManager()->disconnect();
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    private function account(): array
    {
        return ['driver' => 'mercadopago', 'merchant_id' => 'merchant1', 'configuration_version' => 1, 'currency' => 'ARS', 'access_token' => 'token', 'webhook_secret' => 'secret', 'api_url' => 'http://localhost:8090', 'checkout_url' => 'http://localhost:3101'];
    }

    public function test_env_preserves_crlf_comments_and_is_idempotent_with_unique_backup(): void
    {
        $path = $this->root.'/.env';
        $original = "# conservar\r\nOTHER=uno\r\nTOKEN=old\r\n";
        file_put_contents($path, $original);
        $env = new EnvFile($path);
        $values = ['TOKEN' => 'abc$quoted"slash\\tail', 'NEW_VALUE' => 'two words # hash'];
        self::assertSame('updated', $env->write($values));
        self::assertSame($values['TOKEN'], $env->read()['TOKEN']);
        self::assertSame($values['NEW_VALUE'], $env->read()['NEW_VALUE']);
        self::assertStringStartsWith("# conservar\r\nOTHER=uno\r\n", file_get_contents($path));
        self::assertSame('unchanged', $env->write($values));
        self::assertCount(1, glob($path.'.backup-*'));
        self::assertSame($original, file_get_contents(glob($path.'.backup-*')[0]));
    }

    public function test_env_rejects_newlines_before_any_write(): void
    {
        $before = file_get_contents($this->root.'/.env');
        try {
            (new EnvFile($this->root.'/.env'))->write(['TOKEN' => "hello\nINJECTED=true"]);
            self::fail('Debe rechazar inyección');
        } catch (SetupFailure $e) {
            self::assertSame('invalid_env_value', $e->getMessage());
        }
        self::assertSame($before, file_get_contents($this->root.'/.env'));
        self::assertSame([], glob($this->root.'/.env.backup-*'));
    }

    public function test_origin_refuses_remote_credentials_paths_and_tls(): void
    {
        foreach (['https://api.stripe.com', 'http://localhost/plaza', 'http://user:pass@localhost', 'http://localhost?token=secret', 'http://localhost#x', 'http://example.com'] as $url) {
            try {
                EmulatorProvisioner::origin($url);
                self::fail('Origen inseguro aceptado');
            } catch (SetupFailure $e) {
                self::assertSame('unsafe_emulator_endpoint', $e->getMessage());
            }
        }
        self::assertSame('http://[::1]:8090', EmulatorProvisioner::origin('http://[::1]:8090/'));
    }

    private function response(array $value, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($value, JSON_THROW_ON_ERROR));
    }

    private function emulator(array $responses, array &$history): EmulatorProvisioner
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));
        return new EmulatorProvisioner(new Client(['handler' => $stack, 'base_uri' => 'http://localhost:8090']));
    }

    private function page(array $rows, int $lastPage = 1): array
    {
        return ['data' => $rows, 'meta' => ['lastPage' => $lastPage]];
    }

    public function test_emulator_creates_harbor_and_maps_client_secret_without_payment_calls(): void
    {
        $history = [];
        $provisioner = $this->emulator([
            $this->response($this->page([])), $this->response($this->page([])),
            $this->response(['id' => 'merchant1', 'role' => 'merchant']),
            $this->response(['id' => 'app1', 'credentials' => ['accessToken' => 'not-the-paypal-secret', 'clientId' => 'client-id', 'clientSecret' => 'paypal-secret']]),
        ], $history);
        $result = $provisioner->provision('demo-paypal', 'paypal', 'http://localhost:8000/client/payments/webhooks/paypal', [], false, static function (): void {
        });
        self::assertSame('created', $result['status']);
        self::assertSame('paypal-secret', $result['values']['access_token']);
        self::assertSame('client-id', $result['values']['client_id']);
        self::assertSame('merchant1', $result['values']['merchant_id']);
        self::assertSame(['/applications', '/users', '/users', '/applications'], array_map(static fn ($h) => $h['request']->getUri()->getPath(), $history));
        $body = json_decode((string) $history[3]['request']->getBody(), true);
        self::assertSame('harbor', $body['provider']);
        self::assertSame($result['values']['webhook_secret'], $body['webhookSecret']);
    }

    public function test_emulator_finds_exact_name_across_pages_and_second_run_is_read_only(): void
    {
        $history = [];
        $existing = ['id' => 'app1', 'name' => 'demo-mp', 'ownerId' => 'merchant1', 'provider' => 'plaza', 'status' => 'active', 'webhookUrl' => 'http://localhost:8000/client/payments/webhooks/mp'];
        $provisioner = $this->emulator([$this->response($this->page([array_replace($existing, ['name' => 'demo-mp-extra'])], 2)), $this->response($this->page([$existing], 2))], $history);
        $result = $provisioner->provision('demo-mp', 'mercadopago', $existing['webhookUrl'], $this->account(), true, static fn ($merchant) => self::assertSame('merchant1', $merchant));
        self::assertSame('unchanged', $result['status']);
        self::assertSame(['GET', 'GET'], array_map(static fn ($h) => $h['request']->getMethod(), $history));
    }

    public function test_missing_credentials_and_duplicate_names_never_rotate(): void
    {
        $existing = ['id' => 'app1', 'name' => 'demo', 'ownerId' => 'merchant1', 'provider' => 'plaza', 'status' => 'active'];
        foreach ([[$existing], [$existing, $existing]] as $rows) {
            $history = [];
            try {
                $this->emulator([$this->response($this->page($rows))], $history)->provision('demo', 'mercadopago', 'http://localhost/callback', [], false, static function (): void {
                });
                self::fail('Debe rechazar recuperación ambigua');
            } catch (SetupFailure $error) {
                self::assertContains($error->getMessage(), ['emulator_credentials_missing_no_rotation', 'emulator_duplicate_name']);
            }
            self::assertCount(1, $history);
        }
    }

    public function test_emulator_dry_run_and_history_guard_have_no_remote_mutations(): void
    {
        foreach ([false, true] as $historyExists) {
            $history = [];
            $provisioner = $this->emulator([$this->response($this->page([]))], $history);
            try {
                $result = $provisioner->provision('demo', 'stripe', 'http://localhost/callback', [], $historyExists, static function (): void {
                }, true);
                self::assertFalse($historyExists);
                self::assertSame('skipped', $result['status']);
            } catch (SetupFailure $e) {
                self::assertTrue($historyExists);
                self::assertSame('historical_account_requires_existing_application', $e->getMessage());
            }
            self::assertCount(1, $history);
        }
    }

    private function configPartner(): PartnerClientProvisioner
    {
        $path = $this->root.'/storage/app/payments/partner-clients.php';
        $config = ['driver' => 'config', 'config' => ['clients' => is_file($path) ? require $path : [], 'hash_algorithm' => 'bcrypt']];
        return new PartnerClientProvisioner(new ConfigClientRepository($config['config']), $this->capsule->getDatabaseManager(), $config, $path);
    }

    public function test_config_partner_idempotence_account_removal_and_secret_hash_only(): void
    {
        $caps = PartnerClientProvisioner::capabilities(['mp', 'paypal'], true);
        $created = $this->configPartner()->provision('bff', 'tenant', $caps, false);
        self::assertSame('created', $created['status']);
        $file = $this->root.'/storage/app/payments/partner-clients.php';
        $before = file_get_contents($file);
        self::assertStringNotContainsString($created['key'], $before);
        $record = (require $file)['bff'];
        self::assertTrue(password_verify($created['key'], $record['key_hash']));
        self::assertSame('unchanged', $this->configPartner()->provision('bff', 'tenant', $caps, false)['status']);
        self::assertSame($before, file_get_contents($file));
        $next = PartnerClientProvisioner::capabilities(['mp'], false);
        self::assertSame('updated', $this->configPartner()->provision('bff', 'tenant', $next, false)['status']);
        $updated = (require $file)['bff'];
        self::assertSame($record['key_hash'], $updated['key_hash']);
        self::assertSame($next, $updated['capabilities']);
    }

    public function test_config_partner_dry_run_creates_no_file(): void
    {
        self::assertSame('skipped', $this->configPartner()->provision('bff', 'tenant', [], true)['status']);
        self::assertDirectoryDoesNotExist($this->root.'/storage/app');
    }

    private function eloquentPartner(): PartnerClientProvisioner
    {
        $config = ['driver' => 'eloquent', 'eloquent' => ['apps_table' => 'api_apps', 'app_keys_table' => 'api_app_keys', 'hash_algorithm' => 'bcrypt']];
        return new PartnerClientProvisioner(new EloquentClientRepository($config['eloquent']), $this->capsule->getDatabaseManager(), $config, $this->root.'/unused.php');
    }

    private function createPartnerTables(): void
    {
        $schema = $this->capsule->getDatabaseManager()->connection()->getSchemaBuilder();
        $schema->create('api_apps', function ($table): void {
            $table->increments('id');
            $table->string('app_code')->unique();
            $table->string('app_name');
            $table->text('description')->nullable();
            $table->string('owner_email')->nullable();
            $table->boolean('is_active');
            $table->text('config');
            $table->text('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('api_app_keys', function ($table): void {
            $table->increments('id');
            $table->integer('app_id');
            foreach (['label', 'key_prefix', 'key_hash', 'scopes', 'config'] as $name) {
                $table->text($name);
            }
            foreach (['key_ciphertext', 'phrase', 'expires_at', 'revoked_at'] as $name) {
                $table->text($name)->nullable();
            }
            $table->timestamps();
        });
    }

    public function test_eloquent_create_upgrade_and_rerun_preserve_one_key_and_tenant(): void
    {
        $this->createPartnerTables();
        $db = $this->capsule->getDatabaseManager()->connection();
        $created = $this->eloquentPartner()->provision('bff', 'old', ['partner:payments'], false);
        self::assertSame('created', $created['status']);
        $hash = $db->table('api_app_keys')->value('key_hash');
        self::assertTrue(password_verify($created['key'], $hash));
        $caps = PartnerClientProvisioner::capabilities(['mp', 'stripe'], true);
        self::assertSame('updated', $this->eloquentPartner()->provision('bff', 'tenant', $caps, false)['status']);
        self::assertSame('unchanged', $this->eloquentPartner()->provision('bff', 'tenant', $caps, false)['status']);
        self::assertSame(1, $db->table('api_apps')->count());
        self::assertSame(1, $db->table('api_app_keys')->count());
        self::assertSame($hash, $db->table('api_app_keys')->value('key_hash'));
        self::assertSame('tenant', json_decode($db->table('api_apps')->value('config'), true)['tenant_id']);
        self::assertSame(implode(' ', $caps), $db->table('api_app_keys')->value('scopes'));
        $reader = new EloquentClientRepository(['apps_table' => 'api_apps', 'app_keys_table' => 'api_app_keys']);
        self::assertSame($caps, $reader->findByAppCode('bff')->capabilities);
        self::assertSame('tenant', $reader->findByAppCode('bff')->tenantId);
    }

    public function test_eloquent_missing_tables_and_revoked_keys_fail_without_reactivation(): void
    {
        try {
            $this->eloquentPartner()->provision('bff', 'tenant', [], false);
            self::fail('Debe fallar sin migraciones');
        } catch (SetupFailure $e) {
            self::assertSame('partner_tables_not_migrated', $e->getMessage());
        }
        $this->createPartnerTables();
        $this->eloquentPartner()->provision('bff', 'tenant', [], false);
        $db = $this->capsule->getDatabaseManager()->connection();
        $db->table('api_app_keys')->update(['revoked_at' => '2026-01-01 00:00:00']);
        try {
            $this->eloquentPartner()->provision('bff', 'tenant', ['partner:payments:create'], false);
            self::fail('Debe conservar revocación');
        } catch (SetupFailure $e) {
            self::assertSame('partner_key_revoked_or_expired', $e->getMessage());
        }
        self::assertSame(1, $db->table('api_app_keys')->count());
        self::assertSame('2026-01-01 00:00:00', $db->table('api_app_keys')->value('revoked_at'));
    }

    private function command(array $options): array
    {
        $file = $this->root.'/storage/app/payments/partner-clients.php';
        if (is_file($file)) {
            $this->app['config']->set('app-context.client_repository.config.clients', require $file);
        }
        $this->app->instance(ClientRepositoryInterface::class, new ConfigClientRepository($this->app['config']->get('app-context.client_repository.config')));
        $this->app->instance(PaymentRuntime::class, new PaymentRuntime($this->app, $this->app['config']->get('payments')));
        $command = new PaymentSetupCommand();
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $code = $tester->execute($options + ['--json' => true], ['interactive' => false]);
        return [$code, $tester->getDisplay()];
    }

    public function test_command_production_emulator_rejected_before_files_or_database_writes(): void
    {
        $this->app->instance('env', 'production');
        [$code, $output] = $this->command(['--emulator' => 'http://localhost:8090']);
        self::assertSame(1, $code);
        self::assertStringContainsString('emulator_forbidden_in_production', $output);
        self::assertDirectoryDoesNotExist($this->root.'/storage/app');
    }

    public function test_command_config_partner_frontend_and_second_run_without_emulator(): void
    {
        $front = $this->root.'/front.env';
        [$code, $output] = $this->command(['--frontend-env' => $front]);
        self::assertSame(0, $code, $output);
        $env = (new EnvFile($front))->read();
        self::assertGreaterThanOrEqual(32, strlen($env['NUXT_PAYMENTS_SESSION_SECRET']));
        self::assertStringNotContainsString($env['NUXT_PAYMENTS_API_KEY'], $output);
        self::assertStringNotContainsString($env['NUXT_PAYMENTS_SESSION_SECRET'], $output);
        self::assertSame('false', (new EnvFile($this->root.'/.env'))->read()['PAYMENTS_ACCEPT_NEW']);
        [$code, $output] = $this->command(['--frontend-env' => $front]);
        self::assertSame(0, $code, $output);
        foreach (json_decode($output, true, 64, JSON_THROW_ON_ERROR)['steps'] as $step) {
            self::assertContains($step['status'], ['unchanged', 'skipped']);
        }
    }

    public function test_command_dry_run_creates_no_frontend_or_local_state(): void
    {
        [$code, $output] = $this->command(['--dry-run' => true, '--frontend-env' => $this->root.'/front.env']);
        self::assertSame(0, $code, $output);
        self::assertFileDoesNotExist($this->root.'/front.env');
        self::assertDirectoryDoesNotExist($this->root.'/storage/app');
    }

    public function test_command_refuses_historical_identity_even_if_env_was_already_changed(): void
    {
        $identity = (new ConfiguredAccounts(['accounts' => ['mp' => $this->account()]]))->account('mp')->identity();
        $this->capsule->getDatabaseManager()->table('intents')->insert(['data' => json_encode(['account_alias' => 'mp', 'account_identity' => $identity])]);
        $this->app['config']->set('payments.accounts.mp.merchant_id', 'changed');
        [$code, $output] = $this->command(['--dry-run' => true]);
        self::assertSame(1, $code);
        self::assertStringContainsString('account_identity_changed:mp', $output);
    }

    private function enableEmulator(array $responses, array $checkoutResponses): array
    {
        $mapping = [];
        foreach (['merchant_id', 'access_token', 'webhook_secret', 'api_url', 'checkout_url'] as $field) {
            $mapping[$field] = 'PAYMENTS_MP_'.strtoupper($field);
        }
        $this->app['config']->set('payments.setup.accounts.mp', $mapping);
        $this->app->instance(EmulatorProvisioner::class, new EmulatorProvisioner(
            new Client(['handler' => HandlerStack::create(new MockHandler($responses)), 'base_uri' => 'http://localhost:8090']),
            new Client(['handler' => HandlerStack::create(new MockHandler($checkoutResponses))]),
        ));
        $token = $this->root.'/admin.token';
        file_put_contents($token, 'synthetic-admin-test-token');
        return ['--emulator' => 'http://localhost:8090', '--emulator-token-file' => $token, '--frontend-env' => $this->root.'/front.env'];
    }

    public function test_full_emulator_command_opens_only_after_success_and_second_run_is_unchanged(): void
    {
        $existing = ['id' => 'app1', 'name' => 'demo-mp', 'ownerId' => 'merchant1', 'provider' => 'plaza', 'status' => 'active', 'webhookUrl' => 'http://localhost:8000/client/payments/webhooks/mp'];
        $options = $this->enableEmulator([
            $this->response($this->page([])), $this->response($this->page([])),
            $this->response(['id' => 'merchant1', 'role' => 'merchant']),
            $this->response(['id' => 'app1', 'credentials' => ['accessToken' => 'issued-token']]),
            $this->response($this->page([$existing])),
        ], [new Response(200), new Response(200)]);
        [$code, $output] = $this->command($options);
        self::assertSame(0, $code, $output);
        $values = (new EnvFile($this->root.'/.env'))->read();
        self::assertSame('true', $values['PAYMENTS_ACCEPT_NEW']);
        self::assertSame('issued-token', $values['PAYMENTS_MP_ACCESS_TOKEN']);
        self::assertStringNotContainsString('issued-token', $output);
        [$code, $output] = $this->command($options);
        self::assertSame(0, $code, $output);
        foreach (json_decode($output, true, 64, JSON_THROW_ON_ERROR)['steps'] as $step) {
            self::assertContains($step['status'], ['unchanged', 'skipped']);
        }
    }

    public function test_bad_admin_token_never_opens_sales_or_leaks_http_response(): void
    {
        $options = $this->enableEmulator([$this->response(['secret' => 'private-response'], 401)], [new Response(200)]);
        [$code, $output] = $this->command($options);
        self::assertSame(1, $code);
        self::assertStringContainsString('emulator_unauthorized', $output);
        self::assertStringNotContainsString('private-response', $output);
        self::assertStringNotContainsString('synthetic-admin-test-token', $output);
        self::assertSame('false', (new EnvFile($this->root.'/.env'))->read()['PAYMENTS_ACCEPT_NEW']);
    }

    public function test_unbuilt_checkout_prevents_opening_sales_but_keeps_provisioned_credentials(): void
    {
        $options = $this->enableEmulator([
            $this->response($this->page([])), $this->response($this->page([])),
            $this->response(['id' => 'merchant1', 'role' => 'merchant']),
            $this->response(['id' => 'app1', 'credentials' => ['accessToken' => 'issued-token']]),
        ], [new Response(503)]);
        [$code, $output] = $this->command($options);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('checkout_unavailable_build_required', $output);
        $values = (new EnvFile($this->root.'/.env'))->read();
        self::assertSame('false', $values['PAYMENTS_ACCEPT_NEW']);
        self::assertSame('issued-token', $values['PAYMENTS_MP_ACCESS_TOKEN']);
    }
}
