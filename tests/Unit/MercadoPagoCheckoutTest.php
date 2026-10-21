<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Tests\Unit;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ronu\PaymentGateways\CaptureMode;
use Ronu\PaymentGateways\CheckoutStatus;
use Ronu\PaymentGateways\Contracts\HostedCheckout;
use Ronu\PaymentGateways\CreatePayment;
use Ronu\PaymentGateways\Drivers\MercadoPago\MercadoPagoGateway;
use Ronu\PaymentGateways\Exceptions\UnsupportedOperation;
use Ronu\PaymentGateways\GatewayFactory;
use Ronu\PaymentGateways\Money;
use Ronu\PaymentGateways\Payer;
use Ronu\PaymentGateways\PaymentMethod;
use Ronu\PaymentGateways\PaymentStatus;

/** Checkout Pro (hosted), the Card Payment Brick (embedded) and the Payments API they produce. */
final class MercadoPagoCheckoutTest extends TestCase
{
    private function real(MockHttp $http, string $preset = 'mercadopago'): MercadoPagoGateway
    {
        /** @var MercadoPagoGateway */
        return GatewayFactory::make($preset, [
            'credentials' => ['access_token' => 'APP_USR-1'],
            'webhooks' => ['secret' => 'whsec'],
            'options' => ['notification_url' => 'https://shop.test/webhooks/mp'],
        ], $http->client);
    }

    private function request(array $overrides = []): CreatePayment
    {
        return new CreatePayment(...$overrides + [
            'amount' => Money::fromDecimal('1500.50', 'ARS'),
            'reference' => 'ORDER-1',
            'idempotencyKey' => 'checkout-ORDER-1',
            'description' => 'Reserva',
            'returnUrl' => 'https://shop.test/vuelta',
            'payer' => Payer::fromFullName('Ana Pérez', 'ana@example.test', '30111222'),
        ]);
    }

    private function preference(array $overrides = []): array
    {
        return $overrides + [
            'id' => '123456-abc-def', 'external_reference' => 'ORDER-1',
            'init_point' => 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=123456-abc-def',
            'sandbox_init_point' => 'https://sandbox.mercadopago.com.ar/checkout/v1/redirect?pref_id=123456-abc-def',
            'auto_return' => 'approved', 'back_urls' => ['success' => 'https://shop.test/vuelta'],
        ];
    }

    public function test_checkout_pro_creates_a_preference_and_sends_the_buyer_to_init_point(): void
    {
        $http = new MockHttp([MockHttp::json(201, $this->preference())]);
        $checkout = $this->real($http)->createCheckout($this->request(['expiresAt' => new DateTimeImmutable('2026-10-22T12:00:00-03:00')]));

        $request = $http->request(0);
        $this->assertSame('https://api.mercadopago.com/checkout/preferences', (string) $request->getUri());
        $this->assertSame('checkout-ORDER-1', $request->getHeaderLine('X-Idempotency-Key'));
        $body = $http->jsonBody(0);
        $this->assertSame([['id' => 'ORDER-1', 'title' => 'Reserva', 'quantity' => 1, 'currency_id' => 'ARS', 'unit_price' => 1500.5]], $body['items']);
        $this->assertSame('ORDER-1', $body['external_reference']);
        $this->assertSame(['success' => 'https://shop.test/vuelta', 'pending' => 'https://shop.test/vuelta', 'failure' => 'https://shop.test/vuelta'], $body['back_urls']);
        $this->assertSame('approved', $body['auto_return']);
        $this->assertSame('https://shop.test/webhooks/mp', $body['notification_url']);
        $this->assertSame(['email' => 'ana@example.test', 'name' => 'Ana', 'surname' => 'Pérez', 'identification' => ['type' => 'DNI', 'number' => '30111222']], $body['payer']);
        $this->assertTrue($body['expires']);
        $this->assertSame('2026-10-22T12:00:00.000-03:00', $body['expiration_date_to']);

        $this->assertSame('123456-abc-def', $checkout->id);
        $this->assertSame(CheckoutStatus::Open, $checkout->status);
        $this->assertStringStartsWith('https://www.mercadopago.com.ar/checkout/', (string) $checkout->url);
        $this->assertTrue($checkout->returnsToMerchant);
        $this->assertNull($checkout->paymentId);
    }

    public function test_test_credentials_pay_on_the_sandbox_init_point(): void
    {
        $http = new MockHttp([MockHttp::json(201, $this->preference())]);

        $this->assertStringStartsWith('https://sandbox.mercadopago.com.ar/', (string) $this->real($http, 'mercadopago_sandbox')->createCheckout($this->request())->url);
    }

    public function test_checkout_pro_has_no_manual_capture(): void
    {
        $this->expectException(UnsupportedOperation::class);
        $this->real(new MockHttp())->createCheckout($this->request(['captureMode' => CaptureMode::Manual]));
    }

    public function test_get_checkout_finds_the_payment_by_external_reference(): void
    {
        $http = new MockHttp([
            MockHttp::json(200, $this->preference()),
            MockHttp::json(200, ['results' => [['id' => 987654321, 'status' => 'approved', 'external_reference' => 'ORDER-1']]]),
        ]);
        $checkout = $this->real($http)->getCheckout('123456-abc-def');

        $this->assertSame('https://api.mercadopago.com/checkout/preferences/123456-abc-def', (string) $http->request(0)->getUri());
        $this->assertSame('/v1/payments/search', $http->request(1)->getUri()->getPath());
        $this->assertSame('external_reference=ORDER-1&sort=date_created&criteria=desc', $http->request(1)->getUri()->getQuery());
        $this->assertSame(CheckoutStatus::Completed, $checkout?->status);
        $this->assertSame('987654321', $checkout->paymentId);
        $this->assertNull($checkout->url);
    }

    public function test_a_rejected_attempt_leaves_the_checkout_open_and_an_old_one_expires(): void
    {
        $http = new MockHttp([
            MockHttp::json(200, $this->preference()),
            MockHttp::json(200, ['results' => [['id' => 1, 'status' => 'rejected']]]),
            MockHttp::json(200, $this->preference(['expires' => true, 'expiration_date_to' => '2020-01-01T00:00:00.000-03:00'])),
            MockHttp::json(200, ['results' => []]),
        ]);
        $gateway = $this->real($http);

        $this->assertSame(CheckoutStatus::Open, $gateway->getCheckout('123456-abc-def')?->status);
        $this->assertSame(CheckoutStatus::Expired, $gateway->getCheckout('123456-abc-def')?->status);
    }

    public function test_numeric_ids_are_payments_api_payments(): void
    {
        $payment = ['id' => 987654321, 'status' => 'approved', 'status_detail' => 'accredited', 'currency_id' => 'ARS',
            'transaction_amount' => 1500.5, 'transaction_amount_refunded' => 500, 'external_reference' => 'ORDER-1'];
        $http = new MockHttp([
            MockHttp::json(200, $payment),
            MockHttp::json(201, ['id' => 111, 'payment_id' => 987654321, 'amount' => 500, 'status' => 'approved']),
            MockHttp::json(200, $payment),
            MockHttp::json(200, ['id' => 987654321, 'status' => 'authorized', 'transaction_amount' => 1500.5, 'currency_id' => 'ARS']),
        ]);
        $gateway = $this->real($http);

        $read = $gateway->getPayment('987654321');
        $this->assertSame('https://api.mercadopago.com/v1/payments/987654321', (string) $http->request(0)->getUri());
        $this->assertSame(PaymentStatus::PartiallyRefunded, $read?->status);
        $this->assertTrue($read->matches('ORDER-1', Money::fromDecimal('1500.50', 'ARS')));
        $this->assertSame(50_000, $read->refundedAmount?->minor);

        $refund = $gateway->refund('987654321', Money::fromDecimal('500', 'ARS'), 'refund-1');
        $this->assertSame('https://api.mercadopago.com/v1/payments/987654321/refunds', (string) $http->request(1)->getUri());
        $this->assertSame('refund-1', $http->request(1)->getHeaderLine('X-Idempotency-Key'));
        $this->assertSame(['amount' => 500], $http->jsonBody(1));
        $this->assertSame('111', $refund->id);
        $this->assertTrue($refund->succeeded());

        $gateway->capture('987654321', idempotencyKey: 'cap-1');
        $this->assertSame('PUT', $http->request(3)->getMethod());
        $this->assertSame(['capture' => true], $http->jsonBody(3));
    }

    public function test_payments_api_states(): void
    {
        foreach ([
            ['pending', PaymentStatus::RequiresAction], ['in_process', PaymentStatus::Processing], ['authorized', PaymentStatus::Authorized],
            ['approved', PaymentStatus::Captured], ['refunded', PaymentStatus::Refunded], ['cancelled', PaymentStatus::Canceled],
            ['rejected', PaymentStatus::Failed], ['charged_back', PaymentStatus::Disputed], ['in_mediation', PaymentStatus::Disputed], ['??', PaymentStatus::Unknown],
        ] as [$status, $expected]) {
            $this->assertSame($expected, MercadoPagoGateway::mapPaymentStatus($status), $status);
        }
    }

    public function test_a_payment_webhook_names_the_checkout_pro_payment(): void
    {
        $ts = time();
        $body = json_encode(['action' => 'payment.updated', 'type' => 'payment', 'data' => ['id' => '987654321'], 'id' => 12345], JSON_THROW_ON_ERROR);
        $headers = ['x-signature' => "ts={$ts},v1=" . hash_hmac('sha256', "id:987654321;request-id:r;ts:{$ts};", 'whsec'), 'x-request-id' => 'r'];

        $event = $this->real(new MockHttp())->parseWebhook($headers, $body);

        $this->assertSame('987654321', $this->real(new MockHttp())->paymentIdForEvent($event));
    }

    public function test_the_brick_card_token_goes_into_the_order(): void
    {
        $http = new MockHttp([MockHttp::json(201, ['id' => 'ORD01', 'status' => 'processed', 'status_detail' => 'accredited', 'total_amount' => '1500.50', 'external_reference' => 'ORDER-1'])]);
        $payment = $this->real($http)->createPayment($this->request(['paymentMethod' => PaymentMethod::card('ff8080814c11e237014c1ff593b57b4d', 'visa', installments: 3)]));

        $this->assertSame(
            ['id' => 'visa', 'type' => 'credit_card', 'token' => 'ff8080814c11e237014c1ff593b57b4d', 'installments' => 3],
            $http->jsonBody(0)['transactions']['payments'][0]['payment_method'],
        );
        $this->assertSame(PaymentStatus::Captured, $payment->status);
    }

    public function test_a_card_without_its_brand_is_refused_before_calling(): void
    {
        $http = new MockHttp();
        $this->expectException(InvalidArgumentException::class);
        try {
            $this->real($http)->createPayment($this->request(['paymentMethod' => PaymentMethod::card('tok')]));
        } finally {
            $this->assertCount(0, $http->history);
        }
    }

    public function test_on_plaza_the_checkout_is_the_order_and_its_emulator_page(): void
    {
        $http = new MockHttp([MockHttp::json(201, ['id' => 'ORD01', 'status' => 'action_required', 'status_detail' => 'waiting_payment', 'total_amount' => '1500.50', 'external_reference' => 'ORDER-1'])]);
        $gateway = GatewayFactory::make('plaza', ['credentials' => ['access_token' => 'TEST-1'], 'emulator' => ['api_url' => 'http://emu.test', 'checkout_url' => 'http://checkout.test']], $http->client);

        $this->assertInstanceOf(HostedCheckout::class, $gateway);
        $checkout = $gateway->createCheckout($this->request());

        $this->assertSame('http://emu.test/plaza/v1/orders', (string) $http->request(0)->getUri());
        $this->assertSame(['ORD01', 'ORD01', CheckoutStatus::Open], [$checkout->id, $checkout->paymentId, $checkout->status]);
        $this->assertSame('http://checkout.test/plaza/checkout/ORD01', $checkout->url);
        $this->assertFalse($checkout->returnsToMerchant);
    }
}
