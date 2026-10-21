<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ronu\PaymentGateways\CaptureMode;
use Ronu\PaymentGateways\CheckoutStatus;
use Ronu\PaymentGateways\CreatePayment;
use Ronu\PaymentGateways\Drivers\Stripe\StripeGateway;
use Ronu\PaymentGateways\GatewayFactory;
use Ronu\PaymentGateways\Money;
use Ronu\PaymentGateways\Payer;
use Ronu\PaymentGateways\PaymentMethod;
use Ronu\PaymentGateways\PaymentStatus;

/** Checkout Sessions (hosted) and a PaymentMethod from Elements (embedded). */
final class StripeCheckoutTest extends TestCase
{
    private function real(MockHttp $http): StripeGateway
    {
        /** @var StripeGateway */
        return GatewayFactory::make('stripe_test', ['credentials' => ['secret_key' => 'sk_test_1'], 'webhooks' => ['secret' => 'whsec_x']], $http->client);
    }

    private function session(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'cs_test_a1B2', 'object' => 'checkout.session', 'status' => 'open', 'payment_status' => 'unpaid',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_a1B2', 'client_reference_id' => 'ORDER-1',
            'payment_intent' => null, 'expires_at' => 1_793_000_000,
        ];
    }

    public function test_a_checkout_session_for_one_line(): void
    {
        $http = new MockHttp([MockHttp::json(200, $this->session())]);
        $checkout = $this->real($http)->createCheckout(new CreatePayment(
            Money::of(4200, 'USD'), 'ORDER-1', 'checkout-ORDER-1', 'Two tickets',
            'https://shop.test/ok?session={CHECKOUT_SESSION_ID}', 'https://shop.test/cart',
            payer: new Payer('buyer@example.test'), captureMode: CaptureMode::Manual, metadata: ['cart' => '42'],
        ));

        $this->assertSame('https://api.stripe.com/v1/checkout/sessions', (string) $http->request(0)->getUri());
        $this->assertSame('checkout-ORDER-1', $http->request(0)->getHeaderLine('Idempotency-Key'));
        $this->assertSame([
            'mode' => 'payment',
            'line_items' => [['quantity' => '1', 'price_data' => ['currency' => 'usd', 'unit_amount' => '4200', 'product_data' => ['name' => 'Two tickets']]]],
            'success_url' => 'https://shop.test/ok?session={CHECKOUT_SESSION_ID}',
            'cancel_url' => 'https://shop.test/cart',
            'client_reference_id' => 'ORDER-1',
            'customer_email' => 'buyer@example.test',
            'metadata' => ['order_id' => 'ORDER-1', 'cart' => '42'],
            'payment_intent_data' => ['capture_method' => 'manual', 'description' => 'Two tickets', 'metadata' => ['order_id' => 'ORDER-1', 'cart' => '42']],
        ], $http->formBody(0));

        $this->assertSame(['cs_test_a1B2', CheckoutStatus::Open, 'https://checkout.stripe.com/c/pay/cs_test_a1B2'], [$checkout->id, $checkout->status, $checkout->url]);
        $this->assertTrue($checkout->returnsToMerchant);
        $this->assertSame(1_793_000_000, $checkout->expiresAt?->getTimestamp());
    }

    public function test_a_completed_session_names_its_payment_intent(): void
    {
        $http = new MockHttp([MockHttp::json(200, $this->session(['status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_123', 'url' => null]))]);
        $checkout = $this->real($http)->getCheckout('cs_test_a1B2');

        $this->assertSame('https://api.stripe.com/v1/checkout/sessions/cs_test_a1B2', (string) $http->request(0)->getUri());
        $this->assertSame([CheckoutStatus::Completed, 'pi_123'], [$checkout?->status, $checkout->paymentId]);
        $this->assertNull($this->real(new MockHttp())->getCheckout('../x'));
    }

    public function test_a_checkout_session_needs_a_return_url(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->real(new MockHttp())->createCheckout(new CreatePayment(Money::of(100, 'USD'), 'R', 'k'));
    }

    public function test_a_session_webhook_names_the_payment_intent(): void
    {
        $ts = time();
        $body = json_encode(['id' => 'evt_1', 'type' => 'checkout.session.completed', 'data' => ['object' => $this->session(['status' => 'complete', 'payment_intent' => 'pi_123'])]], JSON_THROW_ON_ERROR);
        $event = $this->real(new MockHttp())->parseWebhook(['stripe-signature' => "t={$ts},v1=" . hash_hmac('sha256', "{$ts}.{$body}", 'whsec_x')], $body);

        $this->assertSame(['checkout.session', 'cs_test_a1B2', 'pi_123'], [$event->resourceType, $event->resourceId, $event->paymentId]);
    }

    public function test_an_elements_payment_method_is_confirmed_server_side(): void
    {
        $http = new MockHttp([MockHttp::json(200, ['id' => 'pi_123', 'status' => 'succeeded', 'amount' => 4200, 'amount_received' => 4200, 'currency' => 'usd', 'metadata' => ['order_id' => 'ORDER-1']])]);
        $payment = $this->real($http)->createPayment(new CreatePayment(Money::of(4200, 'USD'), 'ORDER-1', 'k', returnUrl: 'https://shop.test/3ds',
            paymentMethod: PaymentMethod::card('pm_1QabcXYZ')));

        $form = $http->formBody(0);
        $this->assertSame(['true', 'pm_1QabcXYZ', 'https://shop.test/3ds'], [$form['confirm'], $form['payment_method'], $form['return_url']]);
        $this->assertSame(PaymentStatus::Captured, $payment->status);
    }

    public function test_refunds_and_disputes_are_read_from_the_expanded_charge(): void
    {
        $intent = fn (array $charge) => ['id' => 'pi_123', 'status' => 'succeeded', 'amount' => 5000, 'amount_received' => 5000, 'currency' => 'usd',
            'latest_charge' => $charge + ['id' => 'ch_1', 'object' => 'charge', 'amount_captured' => 5000, 'amount_refunded' => 0, 'refunded' => false, 'disputed' => false]];
        $http = new MockHttp([
            MockHttp::json(200, $intent(['amount_refunded' => 1000])),
            MockHttp::json(200, $intent(['amount_refunded' => 5000, 'refunded' => true])),
            MockHttp::json(200, $intent(['disputed' => true])),
            MockHttp::json(200, $intent([])),
        ]);
        $gateway = $this->real($http);

        $partial = $gateway->getPayment('pi_123');
        $this->assertSame('https://api.stripe.com/v1/payment_intents/pi_123?expand%5B%5D=latest_charge', (string) $http->request(0)->getUri());
        $this->assertSame([PaymentStatus::PartiallyRefunded, 1000, 'ch_1'], [$partial?->status, $partial->refundedAmount?->minor, $partial->transactionId]);
        $this->assertSame(PaymentStatus::Refunded, $gateway->getPayment('pi_123')?->status);
        $this->assertSame(PaymentStatus::Disputed, $gateway->getPayment('pi_123')?->status);
        $this->assertSame(PaymentStatus::Captured, $gateway->getPayment('pi_123')?->status);
        // Vector keeps the charge id: nothing to read, still captured.
        $this->assertSame(PaymentStatus::Captured, StripeGateway::mapStatus(['status' => 'succeeded', 'latest_charge' => 'ch_1']));
    }

    public function test_on_vector_the_checkout_is_the_intent_and_its_emulator_page(): void
    {
        $http = new MockHttp([MockHttp::json(200, ['id' => 'pi_123', 'status' => 'requires_action', 'amount' => 4200, 'currency' => 'usd',
            'next_action' => ['redirect_to_url' => ['url' => 'http://emu.test/vector/c/pay/pi_123', 'return_url' => 'https://shop.test/ok']]])]);
        $gateway = GatewayFactory::make('vector', ['credentials' => ['secret_key' => 'sk_test_1'], 'emulator' => ['api_url' => 'http://emu.test']], $http->client);
        $this->assertInstanceOf(StripeGateway::class, $gateway);

        $checkout = $gateway->createCheckout(new CreatePayment(Money::of(4200, 'USD'), 'ORDER-1', 'k', returnUrl: 'https://shop.test/ok'));

        $this->assertSame('http://emu.test/vector/v1/payment_intents', (string) $http->request(0)->getUri());
        $this->assertSame(['pi_123', 'pi_123', 'http://emu.test/vector/c/pay/pi_123'], [$checkout->id, $checkout->paymentId, $checkout->url]);
        $this->assertFalse($checkout->returnsToMerchant);
    }
}
