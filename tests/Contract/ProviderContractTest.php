<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Tests\Contract;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Ronu\PaymentGateways\CaptureMode;
use Ronu\PaymentGateways\Contracts\Gateway;
use Ronu\PaymentGateways\Contracts\HostedCheckout;
use Ronu\PaymentGateways\CreatePayment;
use Ronu\PaymentGateways\GatewayFactory;
use Ronu\PaymentGateways\Money;
use Ronu\PaymentGateways\Payer;
use Ronu\PaymentGateways\PaymentMethod;
use Ronu\PaymentGateways\Tests\Unit\MockHttp;

/**
 * Every write a driver sends, checked against the provider's official OpenAPI —
 * the closest thing to the real API without credentials.
 *
 *   PAYMENT_CONTRACTS_DIR=../payment-emulator-lab/contracts composer test:contract
 *
 * The specs are the ones the Payment Emulator Lab vendors (source URL and SHA-256 in
 * each *.meta.json). They are not copied here. Endpoints a spec does not describe
 * (Mercado Pago Checkout Pro) are reported as skipped.
 */
final class ProviderContractTest extends TestCase
{
    private static string $dir = '';

    /** @var array<string, OpenApiValidator> */
    private static array $specs = [];

    public static function setUpBeforeClass(): void
    {
        self::$dir = rtrim((string) getenv('PAYMENT_CONTRACTS_DIR'), '/\\');
        if (self::$dir === '' || ! is_dir(self::$dir)) {
            self::markTestSkipped('Set PAYMENT_CONTRACTS_DIR to the emulator contracts/ folder.');
        }
    }

    private static function spec(string $file): OpenApiValidator
    {
        return self::$specs[$file] ??= new OpenApiValidator(self::$dir . '/' . $file);
    }

    private function request(array $overrides = []): CreatePayment
    {
        return new CreatePayment(...$overrides + [
            'amount' => Money::fromDecimal('1500.50', 'USD'),
            'reference' => 'ORDER-1',
            'idempotencyKey' => 'key-1',
            'description' => 'Two tickets',
            'returnUrl' => 'https://shop.test/ok',
            'cancelUrl' => 'https://shop.test/cancel',
            'payer' => Payer::fromFullName('Ana Pérez', 'ana@example.test', '30111222'),
            'metadata' => ['cart' => '42'],
        ]);
    }

    /**
     * @param  list<string>  $files  Specs to look the path up in.
     * @return list<string>  "METHOD template: error" lines.
     */
    private function violations(MockHttp $http, array $files, string $base, array &$unknown): array
    {
        $errors = [];
        foreach ($http->history as $entry) {
            /** @var RequestInterface $request */
            $request = $entry['request'];
            $method = $request->getMethod();
            $path = substr($request->getUri()->getPath(), strlen((string) parse_url($base, PHP_URL_PATH)));
            if ($method === 'GET' || $path === '/v1/oauth2/token') {
                continue;
            }

            $raw = (string) $request->getBody();
            $form = str_starts_with($request->getHeaderLine('Content-Type'), 'application/x-www-form-urlencoded');
            if ($form) {
                parse_str($raw, $body);
            } else {
                $body = $raw === '' ? null : json_decode($raw, true);
            }

            $matched = false;
            foreach ($files as $file) {
                $template = self::spec($file)->template($method, $path);
                if ($template === null) {
                    continue;
                }
                $matched = true;
                foreach (self::spec($file)->validate($method, $template, $form ? 'application/x-www-form-urlencoded' : 'application/json', $body) as $error) {
                    $errors[] = "{$method} {$template}: {$error}";
                }
                break;
            }
            if (! $matched) {
                $unknown[] = "{$method} {$path}";
            }
        }

        return $errors;
    }

    public function test_mercado_pago_orders_requests_fit_the_orders_spec(): void
    {
        $order = ['id' => 'ORD01', 'status' => 'processed', 'status_detail' => 'accredited', 'total_amount' => '1500.50', 'currency_id' => 'ARS',
            'external_reference' => 'ORDER-1', 'transactions' => ['payments' => [['id' => 'PAY01', 'amount' => '1500.50']]]];
        $partial=array_replace_recursive($order,['transactions'=>['refunds'=>[['id'=>'REF1','amount'=>'500.00','status'=>'processed']]]]);
        $full=array_replace_recursive($order,['transactions'=>['refunds'=>[['id'=>'REF2','amount'=>'1000.50','status'=>'processed']]]]);
        $preference = ['id' => '123-abc', 'init_point' => 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=123-abc'];
        $http = new MockHttp([
            MockHttp::json(201, $order), MockHttp::json(201, $order), MockHttp::json(201, $order),   // ticket, card, manual
            MockHttp::json(200, $order), MockHttp::json(201, $partial),                               // partial refund
            MockHttp::json(200, $order), MockHttp::json(201, $full),                                 // full refund
            MockHttp::json(200, $order),                                                            // capture
            MockHttp::json(201, $preference),                                                       // Checkout Pro
        ]);
        $gateway = GatewayFactory::make('mercadopago', ['credentials' => ['access_token' => 'APP_USR-1'], 'options' => ['notification_url' => 'https://shop.test/wh']], $http->client);
        $ars = Money::fromDecimal('1500.50', 'ARS');

        $gateway->createPayment($this->request(['amount' => $ars]));
        $gateway->createPayment($this->request(['amount' => $ars, 'paymentMethod' => PaymentMethod::card('ff8080814c11e237014c1ff593b57b4d', 'visa', 3)]));
        $gateway->createPayment($this->request(['amount' => $ars, 'captureMode' => CaptureMode::Manual, 'paymentMethod' => PaymentMethod::card('tok', 'master', debit: true)]));
        $gateway->refund('ORD01', Money::fromDecimal('500', 'ARS'), 'r-1');
        $gateway->refund('ORD01', null, 'r-2');
        $gateway->capture('ORD01');
        $this->assertInstanceOf(HostedCheckout::class, $gateway);
        $gateway->createCheckout($this->request(['amount' => $ars, 'expiresAt' => new DateTimeImmutable('+1 day')]));

        $unknown = [];
        $this->assertSame([], $this->violations($http, ['plaza/orders.json'], 'https://api.mercadopago.com', $unknown));
        $this->assertSame(['POST /checkout/preferences'], $unknown, 'Checkout Pro is not in the Orders spec');
    }

    public function test_stripe_requests_fit_the_stripe_spec(): void
    {
        $intent = ['id' => 'pi_1', 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => 150050, 'currency' => 'usd', 'metadata' => ['order_id' => 'ORDER-1']];
        $http = new MockHttp([
            MockHttp::json(200, $intent), MockHttp::json(200, $intent),                             // with pm_…, without
            MockHttp::json(200, $intent), MockHttp::json(200, $intent),                               // verify currency, capture
            MockHttp::json(200, $intent),                                                           // verify refund currency
            MockHttp::json(200, ['id' => 're_1', 'amount' => 500, 'currency' => 'usd', 'status' => 'succeeded']), MockHttp::json(200, $intent),
            MockHttp::json(200, $intent),                                                           // cancel
            MockHttp::json(200, ['id' => 'cs_test_1', 'status' => 'open', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1']),
        ]);
        $gateway = GatewayFactory::make('stripe_test', ['credentials' => ['secret_key' => 'sk_test_1']], $http->client);

        $gateway->createPayment($this->request(['captureMode' => CaptureMode::Manual, 'paymentMethod' => PaymentMethod::card('pm_card_visa')]));
        $gateway->createPayment($this->request());
        $gateway->capture('pi_1', Money::of(100_000, 'USD'), 'cap-1');
        $gateway->refund('pi_1', Money::of(500, 'USD'), 'ref-1');
        $gateway->cancel('pi_1');
        $this->assertInstanceOf(HostedCheckout::class, $gateway);
        $gateway->createCheckout($this->request(['captureMode' => CaptureMode::Manual, 'expiresAt' => new DateTimeImmutable('+2 hours')]));

        $unknown = [];
        $this->assertSame([], $this->violations($http, ['vector/spec3.json'], 'https://api.stripe.com', $unknown));
        $this->assertSame([], $unknown);
    }

    public function test_paypal_requests_fit_the_orders_and_payments_specs(): void
    {
        $order = fn (string $status, string $intent = 'CAPTURE', array $payments = []) => ['id' => 'O1', 'status' => $status, 'intent' => $intent,
            'purchase_units' => [array_filter(['custom_id' => 'ORDER-1', 'amount' => ['currency_code' => 'USD', 'value' => '1500.50'], 'payments' => $payments])]];
        $captured = $order('COMPLETED', 'CAPTURE', ['captures' => [['id' => 'C1', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'USD', 'value' => '1500.50']]]]);
        $authorized = $order('COMPLETED', 'AUTHORIZE', ['authorizations' => [['id' => 'A1', 'status' => 'CREATED']]]);
        $http = new MockHttp([
            MockHttp::json(201, $order('PAYER_ACTION_REQUIRED')),                                    // createCheckout
            MockHttp::json(201, $order('CREATED', 'AUTHORIZE')),                                     // JS SDK order, manual
            MockHttp::json(200, $order('APPROVED')), MockHttp::json(201, $captured), MockHttp::json(200, $captured),           // capture an order
            MockHttp::json(200, $order('APPROVED', 'AUTHORIZE')), MockHttp::json(201, $authorized), MockHttp::json(200, $authorized),
            MockHttp::json(201, ['id' => 'C2', 'status' => 'COMPLETED']), MockHttp::json(200, $captured),                     // authorize + capture part
            MockHttp::json(200, $captured), MockHttp::json(201, ['id' => 'R1', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'USD', 'value' => '10.00']]), MockHttp::json(200, $captured),
            MockHttp::json(200, $authorized), MockHttp::json(204, []), MockHttp::json(200, $order('VOIDED', 'AUTHORIZE')),     // void
        ]);
        $gateway = GatewayFactory::make('paypal_sandbox', [
            'credentials' => ['access_token' => 'A21'],
            'options' => ['brand_name' => 'Shop', 'shipping_preference' => 'NO_SHIPPING', 'locale' => 'es-AR'],
        ], $http->client);
        $this->assertInstanceOf(HostedCheckout::class, $gateway);

        $gateway->createCheckout($this->request());
        $gateway->createPayment($this->request(['returnUrl' => null, 'cancelUrl' => null, 'captureMode' => CaptureMode::Manual]));
        $gateway->capture('O1', idempotencyKey: 'cap-1');
        $gateway->capture('O1', Money::fromDecimal('1000', 'USD'), 'cap-2');
        $gateway->refund('O1', Money::fromDecimal('10', 'USD'), 'ref-1');
        $gateway->cancel('O1', 'void-1');

        $unknown = [];
        $this->assertSame([], $this->violations($http, ['harbor/orders-v2.json', 'harbor/payments-v2.json'], 'https://api-m.sandbox.paypal.com', $unknown));
        $this->assertSame([], $unknown);
    }
}
