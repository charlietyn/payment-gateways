<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ronu\PaymentGateways\CreatePayment;
use Ronu\PaymentGateways\Drivers\MercadoPago\MercadoPagoGateway;
use Ronu\PaymentGateways\Exceptions\GatewayException;
use Ronu\PaymentGateways\Exceptions\InvalidWebhookSignature;
use Ronu\PaymentGateways\GatewayFactory;
use Ronu\PaymentGateways\Money;
use Ronu\PaymentGateways\Payer;
use Ronu\PaymentGateways\PaymentStatus;

final class MercadoPagoGatewayTest extends TestCase
{
    private const TOKEN = 'TEST-1234567890123456-123456-0123456789abcdef0123456789abcdef-123456789';

    public function test_refund_lookup_selects_identity_not_amount_and_only_sends_get(): void
    {
        $order = $this->order(['transactions' => ['refunds' => [
            ['id' => 'REF1', 'transaction_id' => 'PAY01', 'amount' => '20.00', 'status' => 'processed'],
            ['id' => 'REF2', 'transaction_id' => 'PAY01', 'amount' => '20.00', 'status' => 'processing'],
        ]]]);
        $http = new MockHttp([MockHttp::json(200, $order)]);
        $refund = $this->gateway($http)->getRefund('ORD01ABC', 'REF2');
        self::assertSame('REF2', $refund?->id);
        self::assertSame(2000, $refund?->amount->minor);
        self::assertFalse($refund->succeeded());
        self::assertSame('ORD01ABC', $refund->paymentId);
        self::assertCount(1, $http->history);
        self::assertSame('GET', $http->request(0)->getMethod());
        self::assertFalse($http->request(0)->hasHeader('X-Idempotency-Key'));
    }

    public function test_refund_lookup_absence_does_not_infer_from_aggregate_status(): void
    {
        $http = new MockHttp([MockHttp::json(200, $this->order(['status' => 'refunded'])), MockHttp::json(404, [])]);
        self::assertNull($this->gateway($http)->getRefund('ORD01ABC', 'REF1'));
        self::assertNull($this->gateway($http)->getRefund('ORD01ABC', 'REF1'));
    }

    public function test_refund_lookup_rejects_ambiguous_or_unrelated_evidence(): void
    {
        $valid = $this->order(['transactions' => ['refunds' => [
            ['id' => 'REF1', 'transaction_id' => 'PAY01', 'amount' => '20.00', 'status' => 'processed'],
        ]]]);
        foreach (['order', 'duplicate', 'transaction', 'amount', 'status', 'currency'] as $bad) {
            $order = $valid;
            if ($bad === 'order') $order['id'] = 'ORD_OTHER';
            if ($bad === 'duplicate') $order['transactions']['refunds'][] = $order['transactions']['refunds'][0];
            if ($bad === 'transaction') $order['transactions']['refunds'][0]['transaction_id'] = 'PAY_OTHER';
            if ($bad === 'amount') unset($order['transactions']['refunds'][0]['amount']);
            if ($bad === 'status') unset($order['transactions']['refunds'][0]['status']);
            if ($bad === 'currency') $order['currency_id'] = 'USD';
            $http = new MockHttp([MockHttp::json(200, $order)]);
            try {
                $this->gateway($http)->getRefund('ORD01ABC', 'REF1');
                self::fail('Accepted invalid ' . $bad);
            } catch (GatewayException) {
                self::assertCount(1, $http->history);
                self::assertSame('GET', $http->request(0)->getMethod());
            }
        }
    }

    private function order(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 'ORD01ABC', 'status' => 'action_required', 'status_detail' => 'waiting_payment',
            'total_amount' => '180000.00', 'currency_id' => 'ARS', 'external_reference' => 'ORDER-1',
            'transactions' => ['payments' => [['id' => 'PAY01', 'amount' => '180000.00', 'payment_method' => ['id' => 'pagofacil', 'type' => 'ticket', 'token' => 'secret-token']]]],
        ], $overrides);
    }

    private function gateway(MockHttp $http, array $config = []): MercadoPagoGateway
    {
        /** @var MercadoPagoGateway */
        return GatewayFactory::make('plaza', array_replace_recursive([
            'credentials' => ['access_token' => self::TOKEN],
            'webhooks' => ['secret' => 'whsec'],
            'emulator' => ['api_url' => 'http://emu.test', 'checkout_url' => 'http://checkout.test'],
        ], $config), $http->client);
    }

    public function test_creates_an_order_that_waits_for_the_buyer(): void
    {
        $http = new MockHttp([MockHttp::json(201, $this->order())]);
        $payment = $this->gateway($http)->createPayment(new CreatePayment(
            Money::fromDecimal('180000', 'ARS'), 'ORDER-1', 'idem-1', 'Order · bash',
            payer: Payer::fromFullName('Ana Pérez Gómez', 'ana@example.test', '30111222'),
        ));

        $request = $http->request(0);
        $this->assertSame('http://emu.test/plaza/v1/orders', (string) $request->getUri());
        $this->assertSame('Bearer ' . self::TOKEN, $request->getHeaderLine('Authorization'));
        $this->assertSame('idem-1', $request->getHeaderLine('X-Idempotency-Key'));
        $this->assertSame('plaza/payment-pending', $request->getHeaderLine('X-Emulator-Scenario'));
        $body = $http->jsonBody(0);
        $this->assertSame('180000.00', $body['total_amount']);
        $this->assertSame('180000.00', $body['transactions']['payments'][0]['amount']);
        $this->assertSame('ORDER-1', $body['external_reference']);
        $this->assertSame(['type' => 'DNI', 'number' => '30111222'], $body['payer']['identification']);
        $this->assertSame('Pérez Gómez', $body['payer']['last_name']);

        $this->assertSame(PaymentStatus::RequiresAction, $payment->status);
        $this->assertSame('http://checkout.test/plaza/checkout/ORD01ABC', $payment->nextAction?->url);
        $this->assertFalse($payment->nextAction->returnsToMerchant);
        $this->assertSame('PAY01', $payment->transactionId);
        $this->assertTrue($payment->matches('ORDER-1', Money::of(18_000_000, 'ARS')));
        $this->assertArrayNotHasKey('token', $payment->raw['transactions']['payments'][0]['payment_method']);
    }

    public function test_real_mercado_pago_reads_the_ticket_url_and_sends_no_emulator_header(): void
    {
        $http = new MockHttp([MockHttp::json(201, $this->order(['transactions' => ['payments' => [['payment_method' => ['ticket_url' => 'https://www.mercadopago.com.ar/payments/1/ticket']]]]]))]);
        $gateway = GatewayFactory::make('mercadopago', ['credentials' => ['access_token' => 'APP_USR-1'], 'webhooks' => ['secret' => 'x']], $http->client);
        $payment = $gateway->createPayment(new CreatePayment(Money::of(100, 'ARS'), 'R', 'k'));

        $this->assertSame('https://api.mercadopago.com/v1/orders', (string) $http->request(0)->getUri());
        $this->assertFalse($http->request(0)->hasHeader('X-Emulator-Scenario'));
        $this->assertSame('https://www.mercadopago.com.ar/payments/1/ticket', $payment->nextAction?->url);
    }

    public static function states(): array
    {
        return [
            ['created', 'created', PaymentStatus::Created], ['action_required', 'waiting_payment', PaymentStatus::RequiresAction],
            ['action_required', 'waiting_capture', PaymentStatus::Authorized], ['processed', 'accredited', PaymentStatus::Captured],
            ['processed', 'partially_refunded', PaymentStatus::PartiallyRefunded], ['refunded', 'refunded', PaymentStatus::Refunded],
            ['canceled', 'canceled', PaymentStatus::Canceled], ['failed', 'failed', PaymentStatus::Failed],
            ['charged_back', 'in_process', PaymentStatus::Disputed], ['new_thing', null, PaymentStatus::Unknown],
        ];
    }

    #[DataProvider('states')]
    public function test_maps_every_plaza_state(string $status, ?string $detail, PaymentStatus $expected): void
    {
        $this->assertSame($expected, MercadoPagoGateway::mapStatus($status, $detail));
    }

    public function test_get_returns_null_for_unknown_and_invalid_ids(): void
    {
        $http = new MockHttp([MockHttp::json(404, ['error' => 'not_found'])]);
        $gateway = $this->gateway($http);

        $this->assertNull($gateway->getPayment('ORDXYZ'));
        $this->assertNull($gateway->getPayment('../../etc'));
        $this->assertCount(1, $http->history);
    }

    public function test_paid_amount_is_explicit_evidence_and_never_inferred_from_order_status(): void
    {
        $http = new MockHttp([
            MockHttp::json(200, $this->order(['status' => 'processed', 'total_paid_amount' => '180000.00'])),
            MockHttp::json(200, $this->order(['status' => 'processed'])),
            MockHttp::json(200, $this->order(['status' => 'action_required', 'total_paid_amount' => '0.00'])),
        ]);
        $gateway = $this->gateway($http);
        $this->assertSame(18000000, $gateway->getPayment('ORD01ABC')->capturedAmount->minor);
        $this->assertNull($gateway->getPayment('ORD01ABC')->capturedAmount);
        $this->assertSame(0, $gateway->getPayment('ORD01ABC')->capturedAmount->minor);
    }

    public function test_partial_refund_names_the_transaction_and_full_refund_sends_an_empty_body(): void
    {
        $refunded = $this->order(['status' => 'processed', 'status_detail' => 'partially_refunded', 'transactions' => ['refunds' => [['id' => 'REF1', 'amount' => '50000.00', 'status' => 'processed']]]]);
        $http = new MockHttp([
            MockHttp::json(200, $this->order(['status' => 'processed', 'status_detail' => 'accredited'])),
            MockHttp::json(201, $refunded),
            MockHttp::json(200, $refunded),
            MockHttp::json(201, $this->order(['status' => 'refunded', 'status_detail' => 'refunded', 'transactions' => ['refunds' => [['id'=>'REF2','amount'=>'130000.00','status'=>'processed'],['id'=>'REF1','amount'=>'50000.00','status'=>'processed']]]])),
        ]);
        $gateway = $this->gateway($http);

        $partial = $gateway->refund('ORD01ABC', Money::fromDecimal('50000', 'ARS'), 'r-1');
        $this->assertSame(['transactions' => [['id' => 'PAY01', 'amount' => '50000.00']]], $http->jsonBody(1));
        $this->assertSame('REF1', $partial->id);
        $this->assertSame(5_000_000, $partial->amount->minor);
        $this->assertSame(PaymentStatus::PartiallyRefunded, $partial->payment?->status);

        $full = $gateway->refund('ORD01ABC', null, 'r-2');
        $this->assertSame('{}', (string) $http->request(3)->getBody());
        $this->assertSame('REF2', $full->id);
        $this->assertSame('130000.00', $full->amount->toDecimal());
        $this->assertSame(PaymentStatus::Refunded, $full->payment?->status);
    }

    public function test_refund_response_cannot_invent_success_id_or_amount_and_multiple_new_movements_are_ambiguous(): void
    {
        foreach ([[], [['id'=>'REF1','status'=>'processed']], [['id'=>'REF1','amount'=>'1.00']],
            [['id'=>'REF1','amount'=>'1.00','status'=>'processed'],['id'=>'REF2','amount'=>'1.00','status'=>'processed']]] as $refunds) {
            $http = new MockHttp([MockHttp::json(200,$this->order()), MockHttp::json(201,$this->order(['transactions'=>['refunds'=>$refunds]]))]);
            try { $this->gateway($http)->refund('ORD01ABC',Money::of(100,'ARS'),'stable-key'); $this->fail('Invented refund evidence'); }
            catch (GatewayException $error) { $this->assertFalse($error->retryable); }
        }
    }

    public function test_errors_say_whether_to_retry(): void
    {
        $http = new MockHttp([MockHttp::json(500, ['error' => 'internal_error']), MockHttp::json(400, ['error' => 'bad_request'])]);
        $gateway = $this->gateway($http, ['http' => ['retries' => 0]]);   // retries: TransportTest

        foreach ([[true, 500, 'internal_error'], [false, 400, 'bad_request']] as [$retryable, $status, $code]) {
            try {
                $gateway->createPayment(new CreatePayment(Money::of(100, 'ARS'), 'R', 'k'));
                $this->fail('expected a gateway exception');
            } catch (GatewayException $e) {
                $this->assertSame($retryable, $e->retryable);
                $this->assertSame($status, $e->httpStatus);
                $this->assertSame($code, $e->providerCode);
            }
        }
    }

    private function signed(string $dataId, string $secret = 'whsec', ?int $ts = null): array
    {
        $ts ??= time();
        $body = json_encode(['action' => 'order.updated', 'type' => 'order', 'data' => ['id' => $dataId], 'id' => 'notif_1'], JSON_THROW_ON_ERROR);

        return [['X-Signature' => "ts={$ts},v1=" . hash_hmac('sha256', "id:{$dataId};request-id:req_1;ts:{$ts};", $secret), 'X-Request-Id' => 'req_1'], $body];
    }

    public function test_verifies_plaza_webhooks_and_names_the_order(): void
    {
        [$headers, $body] = $this->signed('ORD01ABC');
        $event = $this->gateway(new MockHttp())->parseWebhook($headers, $body);

        $this->assertSame('notif_1', $event->id);
        $this->assertSame('order.updated', $event->type);
        $this->assertSame('ORD01ABC', $event->paymentId);
    }

    public function test_accepts_the_lower_cased_id_mercado_pago_documents(): void
    {
        $ts = time();
        $body = json_encode(['type' => 'order', 'data' => ['id' => 'ORD01ABC'], 'id' => 'n'], JSON_THROW_ON_ERROR);
        $sig = hash_hmac('sha256', "id:ord01abc;request-id:r;ts:{$ts};", 'whsec');

        $this->assertSame('ORD01ABC', $this->gateway(new MockHttp())->parseWebhook(['x-signature' => "ts={$ts},v1={$sig}", 'x-request-id' => 'r'], $body)->resourceId);
    }

    public function test_rejects_forged_old_and_unconfigured_webhooks(): void
    {
        $cases = [
            [$this->signed('ORD01ABC', 'other'), [], InvalidWebhookSignature::MISMATCH],
            [$this->signed('ORD01ABC', 'whsec', time() - 601), [], InvalidWebhookSignature::OUTSIDE_TOLERANCE],
            [[['x-request-id' => 'r'], '{"data":{"id":"x"}}'], [], InvalidWebhookSignature::MALFORMED],
            [$this->signed('ORD01ABC'), ['webhooks' => ['secret' => '']], InvalidWebhookSignature::NOT_CONFIGURED],
        ];

        foreach ($cases as [[$headers, $body], $config, $reason]) {
            try {
                $this->gateway(new MockHttp(), $config)->parseWebhook($headers, $body);
                $this->fail("expected {$reason}");
            } catch (InvalidWebhookSignature $e) {
                $this->assertSame($reason, $e->reason);
            }
        }
    }
}
