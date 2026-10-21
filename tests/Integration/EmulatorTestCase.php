<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Tests\Integration;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Ronu\PaymentGateways\Contracts\Gateway;
use Ronu\PaymentGateways\Exceptions\InvalidWebhookSignature;
use Ronu\PaymentGateways\WebhookEvent;

/**
 * Runs the drivers against a real Payment Emulator Lab.
 *
 *   PAYMENT_EMULATOR_URL=http://localhost:8090
 *   PAYMENT_EMULATOR_ADMIN_TOKEN=…           (the emulator's ADMIN_TOKEN)
 *   PAYMENT_EMULATOR_SINK_PORT=8095          (optional; a php -S receiver for webhooks)
 *
 * Each test class registers its own application, so nothing depends on data
 * already in the emulator. Webhooks need the emulator's worker running with
 * RUNTIME_OUTBOUND_ENABLED=true and 127.0.0.1 in WEBHOOK_ALLOWED_HOSTS.
 */
abstract class EmulatorTestCase extends TestCase
{
    protected static string $emulator = '';

    protected static Client $control;

    protected static ?string $ownerId = null;

    /** @var resource|null */
    private static $sink = null;

    protected static string $sinkDir = '';

    protected static int $sinkPort = 0;

    public static function setUpBeforeClass(): void
    {
        self::$emulator = rtrim((string) getenv('PAYMENT_EMULATOR_URL'), '/');
        $token = (string) getenv('PAYMENT_EMULATOR_ADMIN_TOKEN');
        if (self::$emulator === '' || $token === '') {
            self::markTestSkipped('Set PAYMENT_EMULATOR_URL and PAYMENT_EMULATOR_ADMIN_TOKEN to run against the emulator.');
        }

        self::$control = new Client(['base_uri' => self::$emulator, 'headers' => ['Authorization' => "Bearer {$token}"], 'http_errors' => true, 'timeout' => 20]);
        self::$ownerId ??= self::merchant();
        self::startSink();
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$sink)) {
            proc_terminate(self::$sink);
            self::$sink = null;
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>  The issued application, credentials included.
     */
    protected static function application(string $provider, string $scenario, string $webhookSecret, array $extra = []): array
    {
        return self::json(self::$control->post('/applications', ['json' => $extra + [
            'name' => "payment-gateways {$provider} " . bin2hex(random_bytes(3)),
            'provider' => $provider,
            'ownerId' => self::$ownerId,
            'scenario' => $scenario,
            'webhookUrl' => self::$sinkPort ? 'http://127.0.0.1:' . self::$sinkPort . "/{$provider}" : null,
            'webhookSecret' => $webhookSecret,
        ]]));
    }

    /** The buyer, on the emulator's hosted page. */
    protected static function buyerPays(string $provider, string $resourceId, string $method, bool $decline = false): array
    {
        return self::json((new Client(['base_uri' => self::$emulator, 'timeout' => 20]))
            ->post("/checkout/{$provider}/{$resourceId}/confirm", ['json' => ['method' => $method, 'decline' => $decline, 'email' => 'buyer@example.test']]));
    }

    /**
     * Webhooks delivered to /{provider}, parsed and verified by the gateway.
     *
     * @return list<WebhookEvent>
     */
    protected function deliveredEvents(Gateway $gateway, string $provider, string $paymentId, int $atLeast = 1, int $timeoutSeconds = 20): array
    {
        if (! self::$sinkPort) {
            $this->markTestSkipped('No webhook sink port configured.');
        }

        $deadline = time() + $timeoutSeconds;
        do {
            $events = [];
            foreach (glob(self::$sinkDir . '/*.json') ?: [] as $file) {
                $record = json_decode((string) file_get_contents($file), true);
                if (($record['path'] ?? '') !== "/{$provider}") {
                    continue;
                }
                try {
                    $event = $gateway->parseWebhook((array) $record['headers'], (string) $record['body']);
                } catch (InvalidWebhookSignature) {
                    continue;
                }
                if ($gateway->paymentIdForEvent($event) === $paymentId) {
                    $events[$event->id] = $event;
                }
            }
            if (count($events) >= $atLeast) {
                return array_values($events);
            }
            usleep(500_000);
        } while (time() < $deadline);

        $this->fail(sprintf('Expected %d verified %s webhook(s) for %s, got %d.', $atLeast, $provider, $paymentId, count($events)));
    }

    private static function merchant(): string
    {
        $user = self::json(self::$control->post('/users', ['json' => [
            'email' => 'payment-gateways-' . bin2hex(random_bytes(4)) . '@example.test',
            'displayName' => 'payment-gateways integration',
            'role' => 'merchant',
            'password' => bin2hex(random_bytes(12)),
        ]]));

        return (string) $user['id'];
    }

    private static function startSink(): void
    {
        $port = (int) (getenv('PAYMENT_EMULATOR_SINK_PORT') ?: 0);
        if ($port === 0 || is_resource(self::$sink)) {
            return;
        }

        self::$sinkPort = $port;
        self::$sinkDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'payment-gateways-sink-' . getmypid();
        @mkdir(self::$sinkDir, 0777, true);

        $command = [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/webhook-sink.php'];
        $env = array_merge(getenv(), ['WEBHOOK_SINK_DIR' => self::$sinkDir]);
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        self::$sink = proc_open($command, [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes, null, $env) ?: null;

        for ($i = 0; $i < 40; $i++) {
            if (@fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2)) {
                return;
            }
            usleep(100_000);
        }
        self::fail("The webhook sink did not start on port {$port}.");
    }

    /** @return array<string, mixed> */
    private static function json(\Psr\Http\Message\ResponseInterface $response): array
    {
        return (array) json_decode((string) $response->getBody(), true);
    }
}
