<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ronu\PaymentGateways\Contracts\DisputeLookup;
use Ronu\PaymentGateways\GatewayFactory;
use Ronu\PaymentGateways\Exceptions\GatewayException;

final class MercadoPagoDisputeTest extends TestCase
{
    private function gateway(MockHttp $http): DisputeLookup
    {
        $gateway = GatewayFactory::make('plaza', [
            'credentials' => ['access_token' => 'TEST-1234567890123456-123456-0123456789abcdef0123456789abcdef-123456789'],
            'emulator' => ['api_url' => 'http://emu.test', 'checkout_url' => 'http://checkout.test'],
            'http' => ['retries' => 0],
        ], $http->client);
        self::assertInstanceOf(DisputeLookup::class, $gateway);
        return $gateway;
    }

    private function evidence(): array
    {
        return ['id' => '234000062890459000', 'payments' => [86439942806], 'currency' => 'ARS', 'amount' => '1000.50', 'coverage_applied' => null];
    }

    private function order(): array
    {
        return ['id' => 'ORD1', 'currency_id' => 'ARS', 'transactions' => ['payments' => [['id' => '86439942806']]]];
    }

    public function test_individual_case_is_correlated_to_order_with_historical_seller_header(): void
    {
        foreach ([['coverage' => null, 'state' => 'opened'], ['coverage' => true, 'state' => 'won'], ['coverage' => false, 'state' => 'lost']] as $case) {
            $data = $this->evidence(); $data['coverage_applied'] = $case['coverage'];
            $http = new MockHttp([MockHttp::json(200, $data), MockHttp::json(200, $this->order())]);
            $result = $this->gateway($http)->getDispute('ORD1', $data['id'], '123456789');
            self::assertSame($case['state'], $result->state);
            self::assertSame(100050, $result->amount->minor);
            self::assertSame('ORD1', $result->paymentId);
            self::assertSame('123456789', $http->request(0)->getHeaderLine('X-Caller-Id'));
            self::assertStringEndsWith('/v1/chargebacks/'.$data['id'], $http->request(0)->getUri()->getPath());
            foreach ($http->history as $request) {
                self::assertSame('GET', $request['request']->getMethod());
                self::assertFalse($request['request']->hasHeader('X-Idempotency-Key'));
            }
        }
    }

    public function test_missing_case_and_order_remain_unresolved(): void
    {
        foreach ([[MockHttp::json(404, [])], [MockHttp::json(200, $this->evidence()), MockHttp::json(404, [])]] as $responses) {
            self::assertNull($this->gateway(new MockHttp($responses))->getDispute('ORD1', '234000062890459000', '123456789'));
        }
    }

    public function test_bad_or_ambiguous_cases_cannot_create_financial_evidence(): void
    {
        foreach (['wrong-case', 'multi-payment', 'missing-coverage', 'string-coverage', 'currency', 'missing-amount', 'extra-decimals', 'unsafe-float', 'unrelated-order', 'unrelated-transaction', 'duplicate-transaction'] as $bad) {
            $case = $this->evidence(); $order = $this->order();
            match ($bad) {
                'wrong-case' => $case['id'] = '999',
                'multi-payment' => $case['payments'][] = 123,
                'string-coverage' => $case['coverage_applied'] = 'false',
                'currency' => $case['currency'] = 'USD',
                'extra-decimals' => $case['amount'] = 1.001,
                'unsafe-float' => $case['amount'] = 900719925474099.5,
                'unrelated-order' => $order['id'] = 'ORD2',
                'unrelated-transaction' => $order['transactions']['payments'][0]['id'] = 'OTHER',
                'duplicate-transaction' => $order['transactions']['payments'][] = $order['transactions']['payments'][0],
                default => null,
            };
            if ($bad === 'missing-coverage') { unset($case['coverage_applied']); }
            if ($bad === 'missing-amount') { unset($case['amount']); }
            $http = new MockHttp([MockHttp::json(200, $case), MockHttp::json(200, $order)]);
            try {
                $this->gateway($http)->getDispute('ORD1', '234000062890459000', '123456789');
                self::fail('Accepted '.$bad);
            } catch (GatewayException) { self::assertLessThanOrEqual(2, count($http->history)); }
        }
    }

    public function test_decimal_json_numbers_and_big_integer_identifiers_remain_exact(): void
    {
        $case = $this->evidence(); $case['amount'] = 1000.5;
        $http = new MockHttp([MockHttp::json(200, $case), MockHttp::json(200, $this->order())]);
        self::assertSame(100050, $this->gateway($http)->getDispute('ORD1', $case['id'], '123456789')->amount->minor);
        $raw = '{"id":234000062890459000001,"payments":[864399428060000000001],"currency":"ARS","amount":"0.01","coverage_applied":null}';
        $order = $this->order(); $order['transactions']['payments'][0]['id'] = '864399428060000000001';
        $http = new MockHttp([new \GuzzleHttp\Psr7\Response(200, [], $raw), MockHttp::json(200, $order)]);
        self::assertSame('234000062890459000001', $this->gateway($http)->getDispute('ORD1', '234000062890459000001', '123456789')->id);
    }
}
