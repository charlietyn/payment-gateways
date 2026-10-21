<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Tests\Unit;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Ronu\PaymentGateways\Exceptions\GatewayException;
use Ronu\PaymentGateways\Http\Transport;

final class TransportTest extends TestCase
{
    /** @var list<int> */
    private array $slept = [];

    private function transport(MockHttp $http, int $retries = 2, ?AbstractLogger $logger = null): Transport
    {
        return new Transport('https://api.test', $http->client, retries: $retries, retryDelayMs: 100, maxRetryDelayMs: 2_000,
            logger: $logger, gateway: 'shop', sleep: function (int $ms): void {
                $this->slept[] = $ms;
            });
    }

    public function test_a_write_with_an_idempotency_key_is_resent_identically(): void
    {
        $http = new MockHttp([MockHttp::json(503, []), MockHttp::json(500, []), MockHttp::json(201, ['id' => 'ok'])]);

        $body = $this->transport($http)->request('POST', '/v1/orders', ['X-Idempotency-Key' => 'k-1'], ['a' => 1]);

        $this->assertSame(['id' => 'ok'], $body);
        $this->assertCount(3, $http->history);
        foreach ([0, 1, 2] as $i) {
            $this->assertSame('k-1', $http->request($i)->getHeaderLine('X-Idempotency-Key'));
            $this->assertSame('{"a":1}', (string) $http->request($i)->getBody());
        }
        $this->assertCount(2, $this->slept);
        $this->assertGreaterThanOrEqual(100, $this->slept[0]);
        $this->assertGreaterThanOrEqual(200, $this->slept[1], 'the backoff doubles');
    }

    public function test_a_write_without_a_key_is_not_resent_after_an_answer(): void
    {
        $http = new MockHttp([MockHttp::json(500, ['error' => 'boom'])]);

        try {
            $this->transport($http)->request('POST', '/v1/oauth2/token', form: ['grant_type' => 'client_credentials']);
            $this->fail('expected the 500');
        } catch (GatewayException $e) {
            $this->assertTrue($e->retryable, 'the caller may retry with a key');
            $this->assertCount(1, $http->history);
        }
    }

    public function test_a_connection_that_never_opened_is_retried_for_any_request(): void
    {
        $http = new MockHttp([new ConnectException('refused', new Request('POST', '/')), MockHttp::json(200, ['access_token' => 't'])]);

        $this->assertSame(['access_token' => 't'], $this->transport($http)->request('POST', '/v1/oauth2/token'));
    }

    public function test_a_lost_response_is_only_retried_when_idempotent(): void
    {
        $lost = fn () => new RequestException('timeout after send', new Request('POST', '/'));
        $http = new MockHttp([$lost(), MockHttp::json(200, ['id' => 'pi_1']), $lost()]);
        $transport = $this->transport($http);

        $this->assertSame(['id' => 'pi_1'], $transport->request('POST', '/v1/payment_intents', ['Idempotency-Key' => 'k']));

        $this->expectException(GatewayException::class);
        $transport->request('POST', '/v1/payment_intents');
    }

    public function test_retry_after_is_honoured_and_a_long_one_fails_retryable(): void
    {
        $http = new MockHttp([new Response(429, ['Retry-After' => '1']), MockHttp::json(200, ['ok' => true]), new Response(429, ['Retry-After' => '60'])]);
        $transport = $this->transport($http);

        $transport->request('GET', '/v1/payments/1');
        $this->assertSame([1000], $this->slept);

        try {
            $transport->request('GET', '/v1/payments/1');
            $this->fail('expected the 429');
        } catch (GatewayException $e) {
            $this->assertSame([429, true], [$e->httpStatus, $e->retryable]);
            $this->assertSame([1000], $this->slept, 'a 60 s wait is not held');
        }
    }

    public function test_client_errors_and_exhausted_retries_stop(): void
    {
        $http = new MockHttp([MockHttp::json(409, []), MockHttp::json(502, []), MockHttp::json(502, []), MockHttp::json(502, [])]);
        $transport = $this->transport($http);

        foreach ([[409, false, 1], [502, true, 4]] as [$status, $retryable, $calls]) {
            try {
                $transport->request('POST', '/x', ['Idempotency-Key' => 'k']);
                $this->fail("expected {$status}");
            } catch (GatewayException $e) {
                $this->assertSame([$status, $retryable], [$e->httpStatus, $e->retryable]);
                $this->assertCount($calls, $http->history);
            }
        }
    }

    public function test_logs_name_the_call_but_never_headers_bodies_or_queries(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, string, array<string, mixed>}> */
            public array $lines = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->lines[] = [(string) $level, (string) $message, $context];
            }
        };
        $http = new MockHttp([MockHttp::json(500, ['error' => 'internal_error']), MockHttp::json(200, ['card' => '4242'])]);

        $this->transport($http, logger: $logger)->request('GET', '/v1/payments/search?external_reference=ORDER-9', ['Authorization' => 'Bearer APP_USR-secret']);

        $this->assertSame(['notice', 'info'], array_column($logger->lines, 0));
        [, , $context] = $logger->lines[1];
        $this->assertSame(['shop', 'GET', '/v1/payments/search', 2, 200], [$context['gateway'], $context['method'], $context['path'], $context['attempt'], $context['status']]);
        $all = json_encode($logger->lines, JSON_THROW_ON_ERROR);
        foreach (['APP_USR-secret', 'ORDER-9', '4242', 'Authorization'] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }
    }
}
