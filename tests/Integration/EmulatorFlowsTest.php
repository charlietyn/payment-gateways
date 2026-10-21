<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Tests\Integration;

use Ronu\PaymentGateways\CreatePayment;
use Ronu\PaymentGateways\GatewayFactory;
use Ronu\PaymentGateways\Money;
use Ronu\PaymentGateways\Payer;
use Ronu\PaymentGateways\PaymentStatus;

/**
 * The same consumer code, three providers: create → buyer pays on the hosted
 * page → (capture) → refund → verified webhooks. Only the preset differs.
 */
final class EmulatorFlowsTest extends EmulatorTestCase
{
    public function test_plaza_mercado_pago_orders(): void
    {
        $secret = 'whsec_' . bin2hex(random_bytes(16));
        $app = self::application('plaza', 'plaza/payment-pending', $secret);
        $gateway = GatewayFactory::make('plaza', [
            'emulator_url' => self::$emulator,
            'emulator_checkout_url' => 'http://checkout.invalid',
            'access_token' => $app['credentials']['accessToken'],
            'webhook_secret' => $secret,
        ]);

        $reference = 'PG-MP-' . bin2hex(random_bytes(4));
        $request = new CreatePayment(Money::fromDecimal('1500.50', 'ARS'), $reference, "create-{$reference}",
            'Integration', payer: Payer::fromFullName('Ana Pérez', 'ana@example.test', '30111222'));
        $payment = $gateway->createPayment($request);
        // A retried request (lost response, double click) reuses the key and the body.
        $this->assertSame($payment->id, $gateway->createPayment($request)->id, 'Same idempotency key, same order.');

        // The same key with a different body is a conflict, never a second order.
        try {
            $gateway->createPayment(new CreatePayment(Money::fromDecimal('99', 'ARS'), $reference, "create-{$reference}"));
            $this->fail('expected an idempotency conflict');
        } catch (\Ronu\PaymentGateways\Exceptions\GatewayException $e) {
            $this->assertSame(409, $e->httpStatus);
            $this->assertFalse($e->retryable);
        }
        $this->assertSame(PaymentStatus::RequiresAction, $payment->status);
        $this->assertStringEndsWith("/plaza/checkout/{$payment->id}", (string) $payment->nextAction?->url);

        self::buyerPays('plaza', $payment->id, 'visa');
        $paid = $gateway->getPayment($payment->id);
        $this->assertSame(PaymentStatus::Captured, $paid?->status);
        $this->assertTrue($paid->matches($reference, Money::of(150_050, 'ARS')));

        $partial = $gateway->refund($payment->id, Money::fromDecimal('500', 'ARS'), "refund-1-{$reference}");
        $this->assertSame(50_000, $partial->amount->minor);
        $this->assertSame(PaymentStatus::PartiallyRefunded, $partial->payment?->status);
        $this->assertSame(PaymentStatus::Refunded, $gateway->refund($payment->id, null, "refund-2-{$reference}")->payment?->status);

        $this->assertNotEmpty($this->deliveredEvents($gateway, 'plaza', $payment->id, 2));
    }

    public function test_vector_stripe_payment_intents(): void
    {
        $secret = 'whsec_' . bin2hex(random_bytes(16));
        $app = self::application('vector', 'vector/payment-requires-action', $secret);
        $gateway = GatewayFactory::make('vector', [
            'emulator_url' => self::$emulator,
            'secret_key' => $app['credentials']['accessToken'],
            'webhook_secret' => $secret,
        ]);

        $reference = 'PG-ST-' . bin2hex(random_bytes(4));
        $payment = $gateway->createPayment(new CreatePayment(Money::of(4200, 'USD'), $reference, "create-{$reference}", 'Integration', 'https://shop.test/back',
            metadata: ['cart' => '42']));

        $this->assertSame(PaymentStatus::RequiresAction, $payment->status);
        $this->assertStringContainsString("/vector/c/pay/{$payment->id}", (string) $payment->nextAction?->url);
        $this->assertNotNull($payment->clientSecret);

        self::buyerPays('vector', $payment->id, 'card');
        $paid = $gateway->getPayment($payment->id);
        $this->assertSame(PaymentStatus::Captured, $paid?->status);
        $this->assertTrue($paid->matches($reference, Money::of(4200, 'USD')));

        $refund = $gateway->refund($payment->id, Money::of(1200, 'USD'), "refund-{$reference}");
        $this->assertTrue($refund->succeeded());
        $this->assertSame(1200, $refund->amount->minor);

        $this->assertNotEmpty($this->deliveredEvents($gateway, 'vector', $payment->id, 2));
    }

    public function test_harbor_paypal_orders_with_capture_after_approval(): void
    {
        $webhookId = 'WH-' . strtoupper(bin2hex(random_bytes(8)));
        $app = self::application('harbor', 'harbor/default', $webhookId);
        $gateway = GatewayFactory::make('harbor', [
            'emulator_url' => self::$emulator,
            'client_id' => $app['credentials']['clientId'],
            'client_secret' => $app['credentials']['clientSecret'],
            'webhook_id' => $webhookId,
        ]);

        $reference = 'PG-PP-' . bin2hex(random_bytes(4));
        $payment = $gateway->createPayment(new CreatePayment(Money::fromDecimal('25.00', 'USD'), $reference, "create-{$reference}", 'Integration',
            'https://shop.test/ok', 'https://shop.test/cancel'));

        $this->assertSame(PaymentStatus::RequiresAction, $payment->status);
        $this->assertStringContainsString("/harbor/checkoutnow?token={$payment->id}", (string) $payment->nextAction?->url);
        $this->assertTrue($payment->nextAction->returnsToMerchant);

        self::buyerPays('harbor', $payment->id, 'harbor');
        $this->assertSame(PaymentStatus::Approved, $gateway->getPayment($payment->id)?->status, 'Approval moves no money.');

        $captured = $gateway->capture($payment->id, null, "capture-{$reference}");
        $this->assertSame(PaymentStatus::Captured, $captured->status);
        $this->assertTrue($captured->matches($reference, Money::of(2500, 'USD')));
        $this->assertNotNull($captured->transactionId);

        $refund = $gateway->refund($payment->id, Money::fromDecimal('10.00', 'USD'), "refund-{$reference}");
        $this->assertSame(1000, $refund->amount->minor);
        $this->assertSame(PaymentStatus::PartiallyRefunded, $refund->payment?->status);

        // CHECKOUT.ORDER.APPROVED, signed with RSA and verified against the downloaded
        // certificate. The emulator does not notify merchant-initiated capture/refund
        // under the passthrough scenario (docs/PLAN.md, hallazgos).
        $events = $this->deliveredEvents($gateway, 'harbor', $payment->id, 1);
        $this->assertSame('CHECKOUT.ORDER.APPROVED', $events[0]->type);
    }

    public function test_a_declined_buyer_leaves_a_failed_payment(): void
    {
        $secret = 'whsec_' . bin2hex(random_bytes(16));
        $app = self::application('plaza', 'plaza/payment-pending', $secret);
        $gateway = GatewayFactory::make('plaza', ['emulator_url' => self::$emulator, 'access_token' => $app['credentials']['accessToken'], 'webhook_secret' => $secret]);

        $reference = 'PG-RJ-' . bin2hex(random_bytes(4));
        $payment = $gateway->createPayment(new CreatePayment(Money::of(10_000, 'ARS'), $reference, "create-{$reference}"));
        self::buyerPays('plaza', $payment->id, 'visa', decline: true);

        $failed = $gateway->getPayment($payment->id);
        $this->assertSame(PaymentStatus::Failed, $failed?->status);
        $this->assertFalse($failed->status->isPaid());
    }
}
