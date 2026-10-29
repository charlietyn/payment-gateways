<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Ronu\PaymentGateways\Config\GatewayConfig;
use Ronu\PaymentGateways\Exceptions\ConfigurationException;
use Ronu\PaymentGateways\Exceptions\GatewayException;
use Ronu\PaymentGateways\GatewayFactory;
use Ronu\PaymentGateways\Http\Transport;
use Ronu\PaymentGateways\Money;

final class IntegrationSafetyTest extends TestCase
{
    public function test_raw_data_and_error_bodies_remove_nested_credentials(): void
    {
        $body = ['id' => 'safe-id', 'payments' => [['client_secret' => 'secret-a'], ['payment_method' => ['token' => 'secret-b', 'cvv' => '123']]]];
        $payment = new \Ronu\PaymentGateways\Payment('stripe', 'pi_x', \Ronu\PaymentGateways\PaymentStatus::Created,
            Money::of(100, 'USD'), 'order', 'created', raw: $body, clientSecret: 'intent-client-value');
        $this->assertSame(['id' => 'safe-id', 'payments' => [[], ['payment_method' => []]]], $payment->raw);
        $this->assertSame('intent-client-value', $payment->clientSecret);
        $this->assertSame($payment->raw, (new GatewayException('refused', body: $body))->body);
    }

    public function test_transport_does_not_expose_the_original_request_in_exception_chains(): void
    {
        $request = new \GuzzleHttp\Psr7\Request('POST', 'https://api.test?secret=value', ['Authorization' => 'secret-value']);
        $http = new MockHttp([new \GuzzleHttp\Exception\ConnectException('sensitive transport detail', $request)]);
        try {
            (new Transport('https://api.test', $http->client))->request('POST', '/payment');
            $this->fail('Expected a network error.');
        } catch (GatewayException $error) {
            $this->assertNull($error->getPrevious());
            $this->assertStringNotContainsString('sensitive', (string) $error);
        }
    }
    public function test_http_configuration_rejects_typo_unbounded_and_invalid_budgets(): void
    {
        foreach ([['timeuot' => 1], ['timeout' => 0], ['timeout' => -1], ['timeout' => INF],
            ['connect_timeout' => 'bad'], ['retries' => 1.5], ['retries' => 11], ['retry_delay_ms' => -1]] as $http) {
            try {
                GatewayConfig::fromArray('test', ['driver' => 'stripe', 'environment' => 'sandbox', 'http' => $http]);
                $this->fail('Invalid configuration was accepted.');
            } catch (ConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_stripe_currency_mismatch_never_sends_a_financial_write(): void
    {
        foreach (['capture', 'refund'] as $operation) {
            $http = new MockHttp([MockHttp::json(200, ['id' => 'pi_test', 'amount' => 1000, 'currency' => 'usd', 'status' => 'succeeded'])]);
            $gateway = GatewayFactory::make('stripe', ['credentials' => ['secret_key' => 'synthetic']], $http->client);
            try {
                $gateway->{$operation}('pi_test', Money::of(100, 'EUR'), 'test-key');
                $this->fail('Currency mismatch was accepted.');
            } catch (\InvalidArgumentException) {
                $this->assertCount(1, $http->history);
                $this->assertSame('GET', $http->request(0)->getMethod());
            }
        }
    }

    public function test_invalid_success_body_is_not_resource_absence(): void
    {
        $http = new MockHttp([new Response(200, [], '<html>proxy failure</html>')]);
        $this->expectException(GatewayException::class);
        (new Transport('https://api.test', $http->client))->request('GET', '/payment', allowNotFound: true);
    }

    public function test_transport_does_not_follow_redirects_to_another_origin(): void
    {
        $http = new MockHttp([new Response(307, ['Location' => 'https://untrusted.test/receive'])]);
        try {
            (new Transport('https://api.test', $http->client))->request('POST', '/payment', ['Authorization' => 'synthetic']);
            $this->fail('Redirect must be rejected.');
        } catch (GatewayException $exception) {
            $this->assertSame(307, $exception->httpStatus);
            $this->assertCount(1, $http->history);
        }
    }
}
