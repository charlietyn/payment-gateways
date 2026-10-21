<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ronu\PaymentGateways\CheckoutStatus;
use Ronu\PaymentGateways\CreatePayment;
use Ronu\PaymentGateways\Drivers\PayPal\PayPalGateway;
use Ronu\PaymentGateways\Exceptions\UnsupportedOperation;
use Ronu\PaymentGateways\GatewayFactory;
use Ronu\PaymentGateways\Money;
use Ronu\PaymentGateways\PaymentMethod;

/** PayPal's approval page is the hosted checkout; the order id is the checkout id. */
final class PayPalCheckoutTest extends TestCase
{
    private function gateway(MockHttp $http): PayPalGateway
    {
        /** @var PayPalGateway */
        return GatewayFactory::make('paypal_sandbox', [
            'credentials' => ['access_token' => 'A21AAfixed'],
            'options' => ['brand_name' => 'Shop', 'shipping_preference' => 'NO_SHIPPING'],
        ], $http->client);
    }

    private function order(string $status): array
    {
        return [
            'id' => '5O190127TN364715T', 'status' => $status, 'intent' => 'CAPTURE',
            'purchase_units' => [['reference_id' => 'default', 'custom_id' => 'ORDER-1', 'amount' => ['currency_code' => 'USD', 'value' => '42.00']]],
            'links' => [['href' => 'https://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T', 'rel' => 'payer-action', 'method' => 'GET']],
        ];
    }

    public function test_create_checkout_returns_the_approval_page(): void
    {
        $http = new MockHttp([MockHttp::json(200, $this->order('PAYER_ACTION_REQUIRED'))]);
        $checkout = $this->gateway($http)->createCheckout(new CreatePayment(Money::of(4200, 'USD'), 'ORDER-1', 'k', 'Tickets', 'https://shop.test/ok', 'https://shop.test/cancel'));

        $this->assertSame('https://api-m.sandbox.paypal.com/v2/checkout/orders', (string) $http->request(0)->getUri());
        $this->assertSame([
            'return_url' => 'https://shop.test/ok', 'cancel_url' => 'https://shop.test/cancel',
            'user_action' => 'PAY_NOW', 'brand_name' => 'Shop', 'shipping_preference' => 'NO_SHIPPING',
        ], $http->jsonBody(0)['payment_source']['paypal']['experience_context']);

        $this->assertSame(['5O190127TN364715T', '5O190127TN364715T', CheckoutStatus::Open], [$checkout->id, $checkout->paymentId, $checkout->status]);
        $this->assertSame('https://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T', $checkout->url);
        $this->assertTrue($checkout->returnsToMerchant);
    }

    public function test_an_approved_order_is_a_completed_checkout_waiting_for_capture(): void
    {
        $http = new MockHttp([MockHttp::json(200, $this->order('APPROVED'))]);
        $checkout = $this->gateway($http)->getCheckout('5O190127TN364715T');

        $this->assertSame(CheckoutStatus::Completed, $checkout?->status);
        $this->assertNull($checkout->url);
    }

    public function test_the_buyer_must_come_back_and_tokens_are_not_taken(): void
    {
        try {
            $this->gateway(new MockHttp())->createCheckout(new CreatePayment(Money::of(100, 'USD'), 'R', 'k'));
            $this->fail('expected a missing returnUrl');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(UnsupportedOperation::class);
        $this->gateway(new MockHttp())->createPayment(new CreatePayment(Money::of(100, 'USD'), 'R', 'k', paymentMethod: PaymentMethod::card('tok')));
    }
}
