<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ronu\PaymentGateways\CreatePayment;
use Ronu\PaymentGateways\GatewayFactory;
use Ronu\PaymentGateways\Money;
use Ronu\PaymentGateways\Orchestration\Account;
use Ronu\PaymentGateways\Orchestration\PaymentError;
use Ronu\PaymentGateways\Orchestration\ProviderView;
use Ronu\PaymentGateways\PaymentStatus;
use Ronu\PaymentGateways\Services;
use Ronu\PaymentGateways\Testing\FakeMercadoPago;

/** A Checkout Pro account stores the PREFERENCE; every read goes through it to the payment. */
final class CheckoutProOrchestrationTest extends TestCase
{
    private FakeMercadoPago $fake;

    private function account(string $api = 'checkout_pro'): Account
    {
        $this->fake = new FakeMercadoPago();
        $gateway = GatewayFactory::make('plaza', [
            'credentials' => ['access_token' => 'TEST-cp'],
            'webhooks' => ['secret' => 'whsec_cp'],
            'emulator' => ['api_url' => 'http://plaza.test'],
        ], new Services($this->fake->client(), sleep: static fn () => null));

        return new Account('mp-ar', 'merchant-1', 'emulator', 'ARS', 1, $gateway, ['https://fake.checkout.test'], api: $api);
    }

    public function test_create_returns_the_preference_waiting_for_the_buyer(): void
    {
        $account = $this->account();
        $payment = ProviderView::create($account, new CreatePayment(Money::fromDecimal('120.00', 'ARS'), 'ref-1', 'key-1'));

        $this->assertMatchesRegularExpression('/^pref-\d+$/', $payment->id);
        $this->assertSame(PaymentStatus::RequiresAction, $payment->status);
        $this->assertSame('https://fake.checkout.test/plaza/checkout/' . $payment->id, $payment->nextAction?->url);
        $this->assertNull($payment->transactionId);
    }

    public function test_reads_keep_the_preference_identity_and_expose_the_payment_as_transaction(): void
    {
        $account = $this->account();
        $amount = Money::fromDecimal('120.00', 'ARS');
        $preference = ProviderView::create($account, new CreatePayment($amount, 'ref-2', 'key-2'))->id;

        $before = ProviderView::payment($account, $preference, $amount, 'ref-2');
        $this->assertSame([$preference, PaymentStatus::RequiresAction], [$before?->id, $before?->status]);

        $this->fake->pay($preference);
        $after = ProviderView::payment($account, $preference, $amount, 'ref-2');
        $this->assertSame($preference, $after?->id);
        $this->assertSame(PaymentStatus::Captured, $after?->status);
        $this->assertSame(substr($preference, 5), $after?->transactionId);
        $this->assertTrue($after->matches('ref-2', $amount));
        $this->assertSame(substr($preference, 5), ProviderView::refundTarget($account, $preference));
    }

    public function test_a_declined_attempt_is_a_failed_payment(): void
    {
        $account = $this->account();
        $amount = Money::fromDecimal('50.00', 'ARS');
        $preference = ProviderView::create($account, new CreatePayment($amount, 'ref-3', 'key-3'))->id;
        $this->fake->decline($preference);

        $this->assertSame(PaymentStatus::Failed, ProviderView::payment($account, $preference, $amount, 'ref-3')?->status);
    }

    public function test_no_refund_target_before_the_buyer_paid(): void
    {
        $account = $this->account();
        $preference = ProviderView::create($account, new CreatePayment(Money::fromDecimal('10.00', 'ARS'), 'ref-4', 'key-4'))->id;

        $this->expectException(PaymentError::class);
        ProviderView::refundTarget($account, $preference);
    }

    public function test_checkout_pro_is_its_own_identity_and_mercado_pago_only(): void
    {
        $this->assertNotSame($this->account('orders')->identity(), $this->account('checkout_pro')->identity());

        $stripe = GatewayFactory::make('vector', ['credentials' => ['secret_key' => 'sk_test_x'], 'emulator' => ['api_url' => 'http://vector.test']]);
        $this->expectException(PaymentError::class);
        new Account('st', 'merchant-2', 'emulator', 'USD', 1, $stripe, ['https://x.test'], api: 'checkout_pro');
    }
}
