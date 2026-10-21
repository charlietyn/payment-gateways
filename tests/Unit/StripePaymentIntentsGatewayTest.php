<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ronu\PaymentGateways\CaptureMode;
use Ronu\PaymentGateways\CreatePayment;
use Ronu\PaymentGateways\Drivers\Stripe\StripePaymentIntentsGateway;
use Ronu\PaymentGateways\Exceptions\InvalidWebhookSignature;
use Ronu\PaymentGateways\GatewayFactory;
use Ronu\PaymentGateways\Money;
use Ronu\PaymentGateways\Payer;
use Ronu\PaymentGateways\PaymentStatus;

final class StripePaymentIntentsGatewayTest extends TestCase
{
    private function intent(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 'pi_123', 'object' => 'payment_intent', 'amount' => 5000, 'amount_received' => 0, 'currency' => 'usd',
            'status' => 'requires_action', 'client_secret' => 'pi_123_secret_abc', 'metadata' => ['order_id' => 'ORDER-1'],
            'next_action' => ['type' => 'redirect_to_url', 'redirect_to_url' => ['url' => 'http://checkout.test/vector/c/pay/pi_123', 'return_url' => 'https://shop.test/back']],
        ], $overrides);
    }

    public function test_creates_a_confirmed_intent_on_the_emulator_with_form_encoding(): void
    {
        $http = new MockHttp([MockHttp::json(200, $this->intent())]);
        $gateway = GatewayFactory::make('vector', ['emulator_url' => 'http://emu.test', 'secret_key' => 'sk_test_51abc', 'webhook_secret' => 'whsec_x'], $http->client);

        $payment = $gateway->createPayment(new CreatePayment(
            Money::of(5000, 'USD'), 'ORDER-1', 'idem-1', 'Two tickets', 'https://shop.test/back',
            payer: new Payer('buyer@example.test'), captureMode: CaptureMode::Manual, metadata: ['cart' => '42'],
        ));

        $request = $http->request(0);
        $this->assertSame('http://emu.test/vector/v1/payment_intents', (string) $request->getUri());
        $this->assertStringStartsWith('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        $this->assertSame('idem-1', $request->getHeaderLine('Idempotency-Key'));
        $this->assertSame('vector/payment-requires-action', $request->getHeaderLine('X-Emulator-Scenario'));
        $form = $http->formBody(0);
        $this->assertSame(['amount' => '5000', 'currency' => 'usd', 'capture_method' => 'manual', 'confirm' => 'true', 'description' => 'Two tickets',
            'receipt_email' => 'buyer@example.test', 'return_url' => 'https://shop.test/back', 'metadata' => ['order_id' => 'ORDER-1', 'cart' => '42']], $form);

        $this->assertSame(PaymentStatus::RequiresAction, $payment->status);
        $this->assertSame('http://checkout.test/vector/c/pay/pi_123', $payment->nextAction?->url);
        // The emulator page does not send the buyer back, whatever return_url says.
        $this->assertFalse($payment->nextAction->returnsToMerchant);
        $this->assertSame('pi_123_secret_abc', $payment->clientSecret);
        $this->assertTrue($payment->matches('ORDER-1', Money::of(5000, 'USD')));
    }

    public function test_real_stripe_leaves_confirmation_to_stripe_js(): void
    {
        $http = new MockHttp([MockHttp::json(200, $this->intent(['status' => 'requires_payment_method', 'next_action' => null]))]);
        $gateway = GatewayFactory::make('stripe', ['secret_key' => 'sk_live_x', 'webhook_secret' => 'w', 'api_version' => '2024-06-20'], $http->client);
        $payment = $gateway->createPayment(new CreatePayment(Money::of(5000, 'USD'), 'ORDER-1', 'idem-1', returnUrl: 'https://shop.test/back'));

        $this->assertSame('https://api.stripe.com/v1/payment_intents', (string) $http->request(0)->getUri());
        $this->assertSame('false', $http->formBody(0)['confirm']);
        $this->assertArrayNotHasKey('return_url', $http->formBody(0));
        $this->assertSame('2024-06-20', $http->request(0)->getHeaderLine('Stripe-Version'));
        $this->assertSame(PaymentStatus::Created, $payment->status);
        $this->assertNull($payment->nextAction);
        $this->assertSame('pi_123_secret_abc', $payment->clientSecret);
    }

    public function test_maps_statuses_including_a_declined_attempt(): void
    {
        $this->assertSame(PaymentStatus::Failed, StripePaymentIntentsGateway::mapStatus(['status' => 'requires_payment_method', 'last_payment_error' => ['code' => 'card_declined']]));
        $this->assertSame(PaymentStatus::Created, StripePaymentIntentsGateway::mapStatus(['status' => 'requires_payment_method']));
        $this->assertSame(PaymentStatus::Authorized, StripePaymentIntentsGateway::mapStatus(['status' => 'requires_capture']));
        $this->assertSame(PaymentStatus::Processing, StripePaymentIntentsGateway::mapStatus(['status' => 'processing']));
        $this->assertSame(PaymentStatus::Captured, StripePaymentIntentsGateway::mapStatus(['status' => 'succeeded']));
        $this->assertSame(PaymentStatus::Canceled, StripePaymentIntentsGateway::mapStatus(['status' => 'canceled']));
    }

    public function test_capture_and_refund(): void
    {
        $http = new MockHttp([
            MockHttp::json(200, $this->intent(['status' => 'succeeded', 'amount_received' => 3000, 'latest_charge' => 'ch_1', 'next_action' => null])),
            MockHttp::json(200, ['id' => 're_1', 'object' => 'refund', 'amount' => 1000, 'currency' => 'usd', 'status' => 'succeeded', 'payment_intent' => 'pi_123']),
            MockHttp::json(200, $this->intent(['status' => 'succeeded', 'next_action' => null])),
        ]);
        $gateway = GatewayFactory::make('vector', ['emulator_url' => 'http://emu.test', 'secret_key' => 'sk_test_51abc'], $http->client);

        $captured = $gateway->capture('pi_123', Money::of(3000, 'USD'), 'cap-1');
        $this->assertSame('http://emu.test/vector/v1/payment_intents/pi_123/capture', (string) $http->request(0)->getUri());
        $this->assertSame(['amount_to_capture' => '3000'], $http->formBody(0));
        $this->assertSame(3000, $captured->capturedAmount?->minor);
        $this->assertSame('ch_1', $captured->transactionId);

        $refund = $gateway->refund('pi_123', Money::of(1000, 'USD'), 'ref-1');
        $this->assertSame(['payment_intent' => 'pi_123', 'amount' => '1000'], $http->formBody(1));
        $this->assertSame('re_1', $refund->id);
        $this->assertTrue($refund->succeeded());
        $this->assertSame(1000, $refund->amount->minor);
    }

    private function signed(array $event, string $secret = 'whsec_x', ?int $ts = null): array
    {
        $ts ??= time();
        $body = json_encode($event, JSON_THROW_ON_ERROR);

        return [['Stripe-Signature' => "t={$ts},v1=" . hash_hmac('sha256', "{$ts}.{$body}", $secret)], $body];
    }

    public function test_verifies_the_signature_over_the_raw_body(): void
    {
        $gateway = GatewayFactory::make('vector', ['emulator_url' => 'http://emu.test', 'secret_key' => 'sk_test_x', 'webhook_secret' => 'whsec_x'], (new MockHttp())->client);

        [$headers, $body] = $this->signed(['id' => 'evt_1', 'type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_123', 'object' => 'payment_intent']]]);
        $event = $gateway->parseWebhook($headers, $body);
        $this->assertSame(['evt_1', 'payment_intent.succeeded', 'pi_123'], [$event->id, $event->type, $event->paymentId]);

        // Real Stripe: a charge event names its intent.
        [$headers, $body] = $this->signed(['id' => 'evt_2', 'type' => 'charge.refunded', 'data' => ['object' => ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_123']]]);
        $this->assertSame('pi_123', $gateway->parseWebhook($headers, $body)->paymentId);

        // Re-encoding the body breaks the signature: callers must pass the raw bytes.
        [$headers, $body] = $this->signed(['id' => 'evt_3', 'type' => 'x', 'data' => ['object' => ['id' => 'pi_1', 'object' => 'payment_intent']]]);
        try {
            $gateway->parseWebhook($headers, json_encode(json_decode($body, true), JSON_PRETTY_PRINT));
            $this->fail('expected a mismatch');
        } catch (InvalidWebhookSignature $e) {
            $this->assertSame(InvalidWebhookSignature::MISMATCH, $e->reason);
        }

        try {
            [$headers, $body] = $this->signed(['id' => 'e', 'type' => 'x'], 'whsec_x', time() - 301);
            $gateway->parseWebhook($headers, $body);
            $this->fail('expected a stale timestamp');
        } catch (InvalidWebhookSignature $e) {
            $this->assertSame(InvalidWebhookSignature::OUTSIDE_TOLERANCE, $e->reason);
        }
    }

    public function test_accepts_any_of_several_v1_signatures_during_secret_rotation(): void
    {
        $gateway = GatewayFactory::make('stripe', ['secret_key' => 'sk_test_x', 'webhook_secret' => 'new'], (new MockHttp())->client);
        $ts = time();
        $body = '{"id":"evt_1","type":"t","data":{"object":{"id":"pi_1","object":"payment_intent"}}}';
        $header = "t={$ts},v1=" . hash_hmac('sha256', "{$ts}.{$body}", 'old') . ',v1=' . hash_hmac('sha256', "{$ts}.{$body}", 'new');

        $this->assertSame('evt_1', $gateway->parseWebhook(['stripe-signature' => $header], $body)->id);
    }
}
