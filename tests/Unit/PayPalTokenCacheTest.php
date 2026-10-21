<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ronu\PaymentGateways\Contracts\Gateway;
use Ronu\PaymentGateways\GatewayFactory;
use Ronu\PaymentGateways\Services;

/** PHP-FPM forgets everything between requests: the OAuth token lives in the shared cache. */
final class PayPalTokenCacheTest extends TestCase
{
    private function gateway(MockHttp $http, ArrayCache $cache): Gateway
    {
        return GatewayFactory::make('paypal_sandbox', ['credentials' => ['client_id' => 'AZDxid', 'client_secret' => 'sec']], new Services($http->client, $cache));
    }

    private function order(): array
    {
        return ['id' => 'O1', 'status' => 'CREATED', 'purchase_units' => [['amount' => ['currency_code' => 'USD', 'value' => '1.00']]]];
    }

    public function test_a_second_process_reuses_the_cached_token(): void
    {
        $cache = new ArrayCache();
        $http = new MockHttp([
            MockHttp::json(200, ['access_token' => 'A21-first', 'expires_in' => 32400]),
            MockHttp::json(200, $this->order()),
            MockHttp::json(200, $this->order()),
        ]);

        $this->gateway($http, $cache)->getPayment('O1');
        $this->gateway($http, $cache)->getPayment('O1');   // a new instance = a new PHP request

        $this->assertCount(3, $http->history, 'one OAuth call, two API calls');
        $this->assertSame('Bearer A21-first', $http->request(2)->getHeaderLine('Authorization'));
        $this->assertCount(1, $cache->items);
        $this->assertMatchesRegularExpression('/^ronu\.payment_gateways\.paypal_token\.[0-9a-f]{40}$/', (string) array_key_first($cache->items));
    }

    public function test_a_revoked_token_is_dropped_and_the_call_retried_once(): void
    {
        $cache = new ArrayCache();
        $cache->set('ronu.payment_gateways.paypal_token.' . sha1('https://api-m.sandbox.paypal.com|AZDxid'), ['token' => 'A21-revoked', 'expires_at' => time() + 3600]);
        $http = new MockHttp([
            MockHttp::json(401, ['error' => 'invalid_token']),
            MockHttp::json(200, ['access_token' => 'A21-new', 'expires_in' => 32400]),
            MockHttp::json(200, $this->order()),
        ]);

        $this->assertSame('O1', $this->gateway($http, $cache)->getPayment('O1')?->id);
        $this->assertSame('Bearer A21-revoked', $http->request(0)->getHeaderLine('Authorization'));
        $this->assertSame('Bearer A21-new', $http->request(2)->getHeaderLine('Authorization'));
    }

    public function test_a_broken_cache_never_blocks_a_payment(): void
    {
        $http = new MockHttp([MockHttp::json(200, ['access_token' => 'A21', 'expires_in' => 32400]), MockHttp::json(200, $this->order())]);

        $this->assertSame('O1', $this->gateway($http, new ArrayCache(broken: true))->getPayment('O1')?->id);
    }
}
