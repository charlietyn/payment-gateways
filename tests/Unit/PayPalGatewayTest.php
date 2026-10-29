<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Ronu\PaymentGateways\CaptureMode;
use Ronu\PaymentGateways\CreatePayment;
use Ronu\PaymentGateways\Drivers\PayPal\PayPalGateway;
use Ronu\PaymentGateways\Exceptions\InvalidWebhookSignature;
use Ronu\PaymentGateways\Exceptions\UnsupportedOperation;
use Ronu\PaymentGateways\GatewayFactory;
use Ronu\PaymentGateways\Money;
use Ronu\PaymentGateways\PaymentStatus;

final class PayPalGatewayTest extends TestCase
{
    public function test_certificate_redirect_is_rejected_without_following_it(): void
    {
        $http = new MockHttp([new Response(302, ['Location' => 'https://untrusted.test/certificate'])]);
        [$headers, $body] = $this->signed(['id' => 'WH-REDIRECT', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => ['id' => 'capture']]);
        try {
            $this->gateway($http)->parseWebhook($headers, $body);
            $this->fail('Redirected certificate must be rejected.');
        } catch (InvalidWebhookSignature) {
            $this->assertCount(1, $http->history);
        }
    }
    private static string $key;

    private static string $certificate;

    public static function setUpBeforeClass(): void
    {
        $private = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'harbor-test'], $private);
        $cert = openssl_csr_sign($csr, null, $private, 1);
        openssl_x509_export($cert, $pem);
        openssl_pkey_export($private, $key);
        self::$certificate = $pem;
        self::$key = $key;
    }

    private function token(): Response
    {
        return MockHttp::json(200, ['access_token' => 'A21AAtoken', 'token_type' => 'Bearer', 'expires_in' => 32400]);
    }

    private function order(string $status, array $payments = [], string $intent = 'CAPTURE'): array
    {
        return [
            'id' => 'O0123456789ABCDEF', 'status' => $status, 'intent' => $intent,
            'purchase_units' => [array_filter(['reference_id' => 'default', 'custom_id' => 'ORDER-1', 'amount' => ['currency_code' => 'USD', 'value' => '50.00'], 'payments' => $payments])],
            'links' => [['href' => 'http://checkout.test/harbor/checkoutnow?token=O0123456789ABCDEF', 'rel' => 'payer-action', 'method' => 'GET']],
        ];
    }

    private function gateway(MockHttp $http, array $config = []): PayPalGateway
    {
        /** @var PayPalGateway */
        return GatewayFactory::make('harbor', array_replace_recursive([
            'credentials' => ['client_id' => 'AZDxid', 'client_secret' => 'sec'],
            'webhooks' => ['id' => 'WH-LOCAL'],
            'emulator' => ['api_url' => 'http://emu.test'],
        ], $config), $http->client);
    }

    public function test_oauth_then_create_an_order_with_return_urls(): void
    {
        $http = new MockHttp([$this->token(), MockHttp::json(201, $this->order('PAYER_ACTION_REQUIRED'))]);
        $payment = $this->gateway($http)->createPayment(new CreatePayment(Money::fromDecimal('50', 'USD'), 'ORDER-1', 'idem-1', 'Tickets', 'https://shop.test/ok', 'https://shop.test/cancel'));

        $token = $http->request(0);
        $this->assertSame('http://emu.test/harbor/v1/oauth2/token', (string) $token->getUri());
        $this->assertSame('Basic ' . base64_encode('AZDxid:sec'), $token->getHeaderLine('Authorization'));
        $this->assertSame(['grant_type' => 'client_credentials'], $http->formBody(0));

        $create = $http->request(1);
        $this->assertSame('Bearer A21AAtoken', $create->getHeaderLine('Authorization'));
        $this->assertSame('idem-1', $create->getHeaderLine('PayPal-Request-Id'));
        $body = $http->jsonBody(1);
        $this->assertSame('CAPTURE', $body['intent']);
        $this->assertSame(['currency_code' => 'USD', 'value' => '50.00'], $body['purchase_units'][0]['amount']);
        $this->assertSame('ORDER-1', $body['purchase_units'][0]['custom_id']);
        $this->assertSame('https://shop.test/ok', $body['payment_source']['paypal']['experience_context']['return_url']);

        $this->assertSame(PaymentStatus::RequiresAction, $payment->status);
        $this->assertSame('http://checkout.test/harbor/checkoutnow?token=O0123456789ABCDEF', $payment->nextAction?->url);
        $this->assertTrue($payment->nextAction->returnsToMerchant);
    }

    public function test_the_token_is_reused_until_it_expires(): void
    {
        $http = new MockHttp([$this->token(), MockHttp::json(200, $this->order('CREATED')), MockHttp::json(200, $this->order('CREATED'))]);
        $gateway = $this->gateway($http);
        $gateway->getPayment('O0123456789ABCDEF');
        $gateway->getPayment('O0123456789ABCDEF');

        $this->assertCount(3, $http->history);
    }

    public function test_capture_after_approval_and_refund_through_the_capture(): void
    {
        $captured = $this->order('COMPLETED', ['captures' => [['id' => 'C0123456789ABCDEF', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'USD', 'value' => '50.00']]]]);
        $partial = $this->order('COMPLETED', ['captures' => [['id' => 'C0123456789ABCDEF', 'status' => 'PARTIALLY_REFUNDED', 'amount' => ['currency_code' => 'USD', 'value' => '50.00']]]]);
        $http = new MockHttp([
            $this->token(),
            MockHttp::json(200, $this->order('APPROVED')),
            MockHttp::json(201, $captured),
            MockHttp::json(200, $captured),
            MockHttp::json(200, $captured),
            MockHttp::json(201, ['id' => 'R1', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'USD', 'value' => '20.00']]),
            MockHttp::json(200, $partial),
        ]);
        $gateway = $this->gateway($http);

        $payment = $gateway->capture('O0123456789ABCDEF', null, 'cap-1');
        $this->assertSame('http://emu.test/harbor/v2/checkout/orders/O0123456789ABCDEF/capture', (string) $http->request(2)->getUri());
        $this->assertSame('cap-1', $http->request(2)->getHeaderLine('PayPal-Request-Id'));
        $this->assertSame(PaymentStatus::Captured, $payment->status);
        $this->assertSame('C0123456789ABCDEF', $payment->transactionId);

        $refund = $gateway->refund('O0123456789ABCDEF', Money::fromDecimal('20', 'USD'), 'ref-1');
        $this->assertSame('http://emu.test/harbor/v2/payments/captures/C0123456789ABCDEF/refund', (string) $http->request(5)->getUri());
        $this->assertSame(['amount' => ['currency_code' => 'USD', 'value' => '20.00']], $http->jsonBody(5));
        $this->assertSame(2000, $refund->amount->minor);
        $this->assertSame(PaymentStatus::PartiallyRefunded, $refund->payment?->status);
    }

    public function test_manual_capture_authorizes_first_and_captures_the_authorization(): void
    {
        $authorized = $this->order('COMPLETED', ['authorizations' => [['id' => 'A0123456789ABCDEF', 'status' => 'CREATED']]], 'AUTHORIZE');
        $http = new MockHttp([
            $this->token(),
            MockHttp::json(200, $this->order('APPROVED', [], 'AUTHORIZE')),
            MockHttp::json(201, $authorized),
            MockHttp::json(200, $authorized),
            MockHttp::json(201, ['id' => 'C0123456789ABCDEF', 'status' => 'COMPLETED']),
            MockHttp::json(200, $this->order('COMPLETED', ['authorizations' => [['id' => 'A0123456789ABCDEF', 'status' => 'CAPTURED']], 'captures' => [['id' => 'C0123456789ABCDEF', 'status' => 'COMPLETED']]], 'AUTHORIZE')),
        ]);

        $payment = $this->gateway($http)->capture('O0123456789ABCDEF', Money::fromDecimal('30', 'USD'), 'cap');

        $this->assertStringEndsWith('/v2/checkout/orders/O0123456789ABCDEF/authorize', (string) $http->request(2)->getUri());
        $this->assertStringEndsWith('/v2/payments/authorizations/A0123456789ABCDEF/capture', (string) $http->request(4)->getUri());
        $this->assertSame(['final_capture' => true, 'amount' => ['currency_code' => 'USD', 'value' => '30.00']], $http->jsonBody(4));
        $this->assertSame(PaymentStatus::Captured, $payment->status);
    }

    public function test_an_unapproved_order_cannot_be_voided(): void
    {
        $http = new MockHttp([$this->token(), MockHttp::json(200, $this->order('PAYER_ACTION_REQUIRED'))]);
        $this->expectException(UnsupportedOperation::class);
        $this->gateway($http)->cancel('O0123456789ABCDEF');
    }

    public function test_maps_order_statuses(): void
    {
        $this->assertSame(PaymentStatus::Approved, PayPalGateway::mapStatus($this->order('APPROVED')));
        $this->assertSame(PaymentStatus::Authorized, PayPalGateway::mapStatus($this->order('COMPLETED', ['authorizations' => [['status' => 'CREATED']]])));
        $this->assertSame(PaymentStatus::Canceled, PayPalGateway::mapStatus($this->order('COMPLETED', ['authorizations' => [['status' => 'VOIDED']]])));
        $this->assertSame(PaymentStatus::Refunded, PayPalGateway::mapStatus($this->order('COMPLETED', ['captures' => [['status' => 'REFUNDED']]])));
        $this->assertSame(PaymentStatus::Failed, PayPalGateway::mapStatus($this->order('COMPLETED', ['captures' => [['status' => 'DECLINED']]])));
    }

    /** Signed exactly like payment-emulator-lab/apps/api/src/providers/harbor/harbor.webhooks.ts */
    private function signed(array $event, string $webhookId = 'WH-LOCAL', ?string $certUrl = 'http://emu.test/harbor/certs/harbor-test.pem', ?int $ts = null): array
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $time = gmdate('Y-m-d\TH:i:s.000\Z', $ts ?? time());
        openssl_sign(sprintf('req-1|%s|%s|%u', $time, $webhookId, crc32($body)), $signature, self::$key, OPENSSL_ALGO_SHA256);

        return [array_filter([
            'paypal-transmission-id' => 'req-1', 'paypal-transmission-time' => $time, 'paypal-auth-algo' => 'SHA256withRSA',
            'paypal-cert-url' => $certUrl, 'paypal-transmission-sig' => base64_encode($signature),
        ]), $body];
    }

    public function test_verifies_rsa_webhooks_with_a_certificate_from_an_allowed_host(): void
    {
        $http = new MockHttp([new Response(200, [], self::$certificate)]);
        [$headers, $body] = $this->signed(['id' => 'WH-1', 'event_type' => 'CHECKOUT.ORDER.APPROVED', 'resource_type' => 'checkout-order', 'resource' => ['id' => 'O0123456789ABCDEF', 'status' => 'APPROVED']]);

        $event = $this->gateway($http)->parseWebhook($headers, $body);

        $this->assertSame('http://emu.test/harbor/certs/harbor-test.pem', (string) $http->request(0)->getUri());
        $this->assertSame(['WH-1', 'CHECKOUT.ORDER.APPROVED', 'O0123456789ABCDEF'], [$event->id, $event->type, $event->paymentId]);
    }

    public function test_never_downloads_a_certificate_from_an_unlisted_host(): void
    {
        $http = new MockHttp();
        [$headers, $body] = $this->signed(['id' => 'WH-1', 'event_type' => 'X', 'resource' => []], certUrl: 'https://attacker.test/cert.pem');

        try {
            $this->gateway($http)->parseWebhook($headers, $body);
            $this->fail('expected an untrusted certificate');
        } catch (InvalidWebhookSignature $e) {
            $this->assertSame(InvalidWebhookSignature::UNTRUSTED_CERTIFICATE, $e->reason);
            $this->assertCount(0, $http->history);
        }
    }

    public function test_rejects_another_webhook_id_and_a_tampered_body(): void
    {
        foreach ([
            $this->signed(['id' => 'WH-1', 'event_type' => 'X', 'resource' => []], 'WH-OTHER'),
            [$this->signed(['id' => 'WH-1', 'event_type' => 'X', 'resource' => []])[0], '{"id":"WH-1","event_type":"Y","resource":[]}'],
        ] as [$headers, $body]) {
            try {
                $this->gateway(new MockHttp(), ['webhooks' => ['certificate' => self::$certificate]])->parseWebhook($headers, $body);
                $this->fail('expected a mismatch');
            } catch (InvalidWebhookSignature $e) {
                $this->assertSame(InvalidWebhookSignature::MISMATCH, $e->reason);
            }
        }
    }

    public function test_a_capture_event_finds_its_order_through_the_up_link(): void
    {
        $http = new MockHttp([
            $this->token(),
            MockHttp::json(200, ['id' => 'C0123456789ABCDEF', 'status' => 'COMPLETED', 'links' => [['rel' => 'up', 'href' => 'http://emu.test/harbor/v2/checkout/orders/O0123456789ABCDEF']]]),
        ]);
        $gateway = $this->gateway($http, ['webhooks' => ['certificate' => self::$certificate]]);
        [$headers, $body] = $this->signed(['id' => 'WH-2', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource_type' => 'capture', 'resource' => ['id' => 'C0123456789ABCDEF']]);

        $event = $gateway->parseWebhook($headers, $body);
        $this->assertNull($event->paymentId);
        $this->assertSame('O0123456789ABCDEF', $gateway->paymentIdForEvent($event));
    }

    public function test_manual_capture_mode_creates_an_authorize_intent(): void
    {
        $http = new MockHttp([$this->token(), MockHttp::json(201, $this->order('CREATED', [], 'AUTHORIZE'))]);
        $this->gateway($http)->createPayment(new CreatePayment(Money::of(5000, 'USD'), 'ORDER-1', 'k', captureMode: CaptureMode::Manual));

        $this->assertSame('AUTHORIZE', $http->jsonBody(1)['intent']);
        $this->assertArrayNotHasKey('payment_source', $http->jsonBody(1));
    }
}
