<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Tests\Orchestration;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Ronu\PaymentGateways\CreatePayment;
use Ronu\PaymentGateways\Exceptions\GatewayException;
use Ronu\PaymentGateways\Laravel\PaymentGatewaysServiceProvider;
use Ronu\PaymentGateways\Money;
use Ronu\PaymentGateways\Orchestration\Contracts\FailureReporter;
use Ronu\PaymentGateways\Orchestration\Laravel\Events\PaymentFailureReported;
use Ronu\PaymentGateways\Orchestration\Laravel\{FailureReporting, PaymentController, PaymentRuntime, ProductAdapter};
use Ronu\PaymentGateways\Orchestration\ProviderView;

/**
 * The orchestration answers a failure instead of throwing it. These cases pin the trace it leaves:
 * a log line and an event, with identifiers only, that can never change the payment answer.
 */
final class FailureReportingTest extends TestCase
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $lines = [];

    private function logger(): AbstractLogger
    {
        return new class ($this->lines) extends AbstractLogger {
            /** @param list<array{level: string, message: string, context: array<string, mixed>}> $lines */
            public function __construct(private array &$lines)
            {
            }
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->lines[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }

    /** @param array<string, mixed> $payments */
    private function container(array $payments = []): Container
    {
        $app = new Container();
        $app->instance('config', new Repository(['payments' => $payments]));
        $app->instance('log', $this->logger());
        $app->instance('events', new Dispatcher($app));
        return $app;
    }

    public function test_a_failure_leaves_one_log_line_and_one_event_with_identifiers_only(): void
    {
        $events = new Dispatcher(new Container());
        $seen = [];
        $events->listen(PaymentFailureReported::class, function (PaymentFailureReported $event) use (&$seen): void {
            $seen[] = $event;
        });
        $error = new GatewayException('Payment provider request failed; remote outcome may be unknown.', true);

        (new FailureReporting($this->logger(), $events))->report(FailureReporter::CHECKOUT, $error, ['operation' => 'op-1', 'intent' => 'in-1', 'account' => 'mp-ar']);

        self::assertCount(1, $this->lines);
        self::assertSame('error', $this->lines[0]['level']);
        self::assertSame(
            ['operation' => 'op-1', 'intent' => 'in-1', 'account' => 'mp-ar', 'stage' => 'checkout', 'exception' => GatewayException::class, 'retryable' => true, 'status' => null, 'provider_code' => null],
            array_diff_key($this->lines[0]['context'], ['at' => true])
        );
        self::assertCount(1, $seen);
        self::assertSame([FailureReporter::CHECKOUT, $error, 'op-1'], [$seen[0]->stage, $seen[0]->error, $seen[0]->context['operation']]);
    }

    public function test_the_message_of_a_foreign_exception_is_never_logged(): void
    {
        (new FailureReporting($this->logger()))->report(FailureReporter::REQUEST, new \RuntimeException('SQLSTATE password=hunter2'), ['route' => 'client.payments.checkouts.create']);

        self::assertStringNotContainsString('hunter2', json_encode($this->lines, JSON_THROW_ON_ERROR));
        self::assertSame(\RuntimeException::class, $this->lines[0]['context']['exception']);
        self::assertSame('FailureReportingTest.php', strtok($this->lines[0]['context']['at'], ':'));
    }

    public function test_a_failing_logger_or_listener_cannot_escape(): void
    {
        $events = new Dispatcher(new Container());
        $events->listen(PaymentFailureReported::class, fn () => throw new \LogicException('listener'));
        $logger = new class () extends AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \LogicException('logger');
            }
        };

        (new FailureReporting($logger, $events))->report(FailureReporter::REFUND, new \RuntimeException('x'));

        $this->addToAssertionCount(1);
    }

    public function test_the_runtime_logs_provider_calls_and_dispatches_through_the_host(): void
    {
        $app = $this->container([
            'default_account' => 'mp-ar', 'accept_new' => true,
            'accounts' => ['mp-ar' => [
                'driver' => 'mercadopago', 'environment' => 'emulator', 'api' => 'checkout_pro', 'currency' => 'ARS',
                'merchant_id' => 'usr_fixture', 'configuration_version' => 1, 'access_token' => 'TEST-fake-not-a-credential',
                'webhook_secret' => 'whsec-fake', 'api_url' => 'http://127.0.0.1:9', 'checkout_url' => 'http://127.0.0.1:9',
            ]],
        ]);
        // The provider is unreachable: the exact failure that used to leave no trace at all.
        $refused = new MockHandler([new ConnectException('Connection refused', new PsrRequest('POST', 'http://127.0.0.1:9'))]);
        $app->instance(PaymentGatewaysServiceProvider::HTTP, new Client(['handler' => HandlerStack::create($refused)]));
        $runtime = new PaymentRuntime($app, $app->make('config')->get('payments'));

        try {
            ProviderView::create($runtime->accounts()->account('mp-ar'), new CreatePayment(Money::fromDecimal('70000.00', 'ARS'), 'ref-1', 'idem-1', description: 'Fixture'));
            self::fail('The unreachable provider did not fail.');
        } catch (GatewayException $error) {
            self::assertTrue($error->retryable);
        }
        self::assertCount(1, $this->lines);
        self::assertStringStartsWith('payment-gateways:', $this->lines[0]['message']);
        self::assertStringNotContainsString('TEST-fake', json_encode($this->lines, JSON_THROW_ON_ERROR));

        $seen = 0;
        $app->make('events')->listen(PaymentFailureReported::class, function () use (&$seen): void {
            $seen++;
        });
        $runtime->failures()->report(FailureReporter::CHECKOUT, $error, ['operation' => 'op-1']);
        self::assertSame(1, $seen);
        self::assertSame('payments: {stage} failed ({exception})', $this->lines[1]['message']);
    }

    public function test_an_unexpected_failure_of_an_http_action_is_reported_and_still_answers_unavailable(): void
    {
        $adapter = $this->createMock(ProductAdapter::class);
        $adapter->method('actor')->willThrowException(new \RuntimeException('SQLSTATE password=hunter2'));
        $app = $this->container(['adapter' => $adapter::class]);
        $app->instance($adapter::class, $adapter);
        $seen = [];
        $app->make('events')->listen(PaymentFailureReported::class, function (PaymentFailureReported $event) use (&$seen): void {
            $seen[] = $event->stage;
        });
        $request = Request::create(
            '/client/payments/checkouts',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'request-1'],
            content: json_encode(['subject' => 'EROS-Q-1', 'quoteRevision' => 'v1'], JSON_THROW_ON_ERROR)
        );

        $response = (new PaymentController(new PaymentRuntime($app, $app->make('config')->get('payments'))))->checkout($request);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(['error' => 'payment_unavailable'], $response->getData(true));
        self::assertSame([FailureReporter::REQUEST], $seen);
        self::assertStringNotContainsString('hunter2', json_encode($this->lines, JSON_THROW_ON_ERROR));
    }
}
