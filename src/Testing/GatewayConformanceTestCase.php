<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Testing;

use PHPUnit\Framework\TestCase;
use Ronu\PaymentGateways\Capability;
use Ronu\PaymentGateways\CaptureMode;
use Ronu\PaymentGateways\CheckoutStatus;
use Ronu\PaymentGateways\Contracts\Gateway;
use Ronu\PaymentGateways\Contracts\HostedCheckout;
use Ronu\PaymentGateways\CreatePayment;
use Ronu\PaymentGateways\Exceptions\GatewayException;
use Ronu\PaymentGateways\Exceptions\InvalidWebhookSignature;
use Ronu\PaymentGateways\Exceptions\UnsupportedOperation;
use Ronu\PaymentGateways\Money;
use Ronu\PaymentGateways\Payer;
use Ronu\PaymentGateways\Payment;
use Ronu\PaymentGateways\PaymentStatus;

/**
 * What every driver must do, written once. A new driver extends this with three
 * methods and passes it before it is merged:
 *
 *     final class AcmeConformanceTest extends GatewayConformanceTestCase
 *     {
 *         protected function gateway(): Gateway { return GatewayFactory::make('acme_sandbox', [...]); }
 *         protected function currency(): string { return 'USD'; }
 *         protected function buyerPays(Gateway $gateway, string $resourceId): void { ...the buyer approves... }
 *     }
 *
 * The suite only calls the public contract (Gateway, HostedCheckout, Capability), so
 * it runs against a fake, the Payment Emulator Lab or a provider sandbox. Behaviour a
 * driver declares it lacks (supports() false) is skipped, never assumed.
 *
 * Needs phpunit/phpunit, which the library does not require: it is for your tests.
 */
abstract class GatewayConformanceTestCase extends TestCase
{
    private ?Gateway $gateway = null;

    /** A gateway ready to take payments, with a webhook secret configured. */
    abstract protected function gateway(): Gateway;

    /** A currency the account settles in. */
    abstract protected function currency(): string;

    /**
     * The buyer completes what Payment::$nextAction (or Checkout::$url) asks: pays on
     * the hosted page, approves in the wallet. $resourceId is the payment or checkout id.
     */
    abstract protected function buyerPays(Gateway $gateway, string $resourceId): void;

    /** A well-formed id the provider has never issued. */
    protected function unknownPaymentId(): string
    {
        return 'UNKNOWN' . strtoupper(bin2hex(random_bytes(8)));
    }

    /**
     * A webhook the provider signed for this payment, or null when the test cannot
     * produce one synchronously (then the signed-webhook test is skipped).
     *
     * @return array{0: array<string, string|list<string>>, 1: string}|null  Headers and raw body.
     */
    protected function signedWebhook(Gateway $gateway, Payment $payment): ?array
    {
        return null;
    }

    /**
     * Whether getPayment() reflects refunds. A driver that cannot read them back
     * (the provider answer lacks them) overrides this and says why.
     */
    protected function readsRefundsBack(): bool
    {
        return true;
    }

    protected function amount(string $decimal = '120.00'): Money
    {
        return Money::fromDecimal($decimal, $this->currency());
    }

    /** @param  array<string, mixed>  $overrides  CreatePayment named arguments. */
    protected function request(array $overrides = []): CreatePayment
    {
        $reference = 'CONF-' . strtoupper(bin2hex(random_bytes(5)));

        return new CreatePayment(...$overrides + [
            'amount' => $this->amount(),
            'reference' => $reference,
            'idempotencyKey' => "create-{$reference}",
            'description' => 'Conformance',
            'returnUrl' => 'https://shop.example/return',
            'cancelUrl' => 'https://shop.example/cancel',
            'payer' => Payer::fromFullName('Ana Pérez', 'buyer@example.test', '30111222'),
            'metadata' => ['suite' => 'conformance'],
        ]);
    }

    private function subject(): Gateway
    {
        return $this->gateway ??= $this->gateway();
    }

    /** Pays a fresh payment, capturing it when the provider waits for the merchant. */
    private function paidPayment(): Payment
    {
        $gateway = $this->subject();
        $request = $this->request();
        $payment = $gateway->createPayment($request);
        $this->buyerPays($gateway, $payment->id);

        $paid = $gateway->getPayment($payment->id);
        if ($paid !== null && $paid->status->awaitsMerchant()) {
            $paid = $gateway->capture($paid->id, idempotencyKey: "capture-{$request->reference}");
        }
        $this->assertNotNull($paid);
        $this->assertSame(PaymentStatus::Captured, $paid->status, 'Paid by the buyer (and captured): captured.');
        $this->assertTrue($paid->matches($request->reference, $request->amount), 'The reference and the amount survive the round trip.');

        return $paid;
    }

    public function test_it_names_itself_and_its_capabilities_match_its_interfaces(): void
    {
        $gateway = $this->subject();

        $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $gateway->name());
        $this->assertSame($gateway->supports(Capability::HostedCheckout), $gateway instanceof HostedCheckout,
            'supports(HostedCheckout) and implementing HostedCheckout go together.');
        if ($gateway->supports(Capability::PartialCapture)) {
            $this->assertTrue($gateway->supports(Capability::ManualCapture), 'Partial capture implies manual capture.');
        }
    }

    public function test_a_new_payment_echoes_the_request_and_waits_for_the_buyer(): void
    {
        $request = $this->request();
        $payment = $this->subject()->createPayment($request);

        $this->assertNotSame('', $payment->id);
        $this->assertSame($this->subject()->name(), $payment->gateway);
        $this->assertTrue($payment->matches($request->reference, $request->amount));
        $this->assertFalse($payment->status->isPaid(), 'Nothing is paid before the buyer acts.');
        $this->assertNotSame(PaymentStatus::Unknown, $payment->status);
        if ($payment->status === PaymentStatus::RequiresAction) {
            $this->assertNotNull($payment->nextAction, 'requires_action says where the buyer goes.');
            $this->assertMatchesRegularExpression('#^https?://#', $payment->nextAction->url);
        }
    }

    public function test_the_same_idempotency_key_returns_the_same_payment(): void
    {
        $request = $this->request();

        $this->assertSame($this->subject()->createPayment($request)->id, $this->subject()->createPayment($request)->id);
    }

    public function test_get_payment_reads_it_back_and_unknown_ids_are_null(): void
    {
        $request = $this->request();
        $created = $this->subject()->createPayment($request);
        $read = $this->subject()->getPayment($created->id);

        $this->assertNotNull($read);
        $this->assertSame($created->id, $read->id);
        $this->assertSame($created->status, $read->status);
        $this->assertTrue($read->matches($request->reference, $request->amount));

        $this->assertNull($this->subject()->getPayment($this->unknownPaymentId()));
        $this->assertNull($this->subject()->getPayment('../../etc/passwd'), 'Ids are never pasted into a path unchecked.');
    }

    public function test_the_buyer_pays_and_the_payment_is_captured(): void
    {
        $paid = $this->paidPayment();

        $this->assertTrue($paid->status->isPaid());
        $this->assertTrue($paid->status->isFinal());
        $this->assertNull($paid->nextAction);
    }

    public function test_partial_then_full_refund(): void
    {
        if (! $this->subject()->supports(Capability::PartialRefund)) {
            $this->markTestSkipped('The driver does not support partial refunds.');
        }
        $paid = $this->paidPayment();
        $part = $this->amount('20.00');

        $partial = $this->subject()->refund($paid->id, $part, "refund-1-{$paid->id}");
        $this->assertTrue($partial->succeeded(), "Refund status: {$partial->status}");
        $this->assertTrue($part->equals($partial->amount));
        $this->assertSame($paid->id, $partial->paymentId);

        $rest = $this->subject()->refund($paid->id, null, "refund-2-{$paid->id}");
        $this->assertTrue($rest->succeeded(), "Refund status: {$rest->status}");
        $this->assertTrue($this->amount('100.00')->equals($rest->amount), 'A refund without amount returns what remains.');

        if ($this->readsRefundsBack()) {
            $this->assertSame(PaymentStatus::PartiallyRefunded, $partial->payment->status ?? $this->subject()->getPayment($paid->id)?->status);
            $this->assertSame(PaymentStatus::Refunded, $this->subject()->getPayment($paid->id)?->status);
        }
    }

    public function test_refunding_more_than_was_captured_fails_and_is_not_retryable(): void
    {
        $paid = $this->paidPayment();

        try {
            $this->subject()->refund($paid->id, $this->amount('999.00'), "refund-too-much-{$paid->id}");
            $this->fail('Refunding more than was captured must fail.');
        } catch (GatewayException $e) {
            $this->assertFalse($e->retryable, 'The same request will fail again.');
        }
    }

    public function test_a_captured_payment_cannot_be_canceled(): void
    {
        $paid = $this->paidPayment();

        try {
            $this->subject()->cancel($paid->id, "cancel-{$paid->id}");
            $this->fail('Canceling a captured payment must fail: refund it instead.');
        } catch (GatewayException $e) {
            $this->assertFalse($e->retryable, "{$e->httpStatus} {$e->providerCode}: {$e->getMessage()}");
        }
        $this->assertSame(PaymentStatus::Captured, $this->subject()->getPayment($paid->id)?->status);
    }

    public function test_an_unpaid_payment_is_canceled_or_left_to_expire(): void
    {
        $payment = $this->subject()->createPayment($this->request());

        try {
            $canceled = $this->subject()->cancel($payment->id, "cancel-{$payment->id}");
        } catch (UnsupportedOperation) {
            // Some providers have nothing to cancel before approval (PayPal: the order expires).
            $this->addToAssertionCount(1);

            return;
        }
        $this->assertSame(PaymentStatus::Canceled, $canceled->status);
        $this->assertSame(PaymentStatus::Canceled, $this->subject()->getPayment($payment->id)?->status);
    }

    public function test_manual_capture_waits_for_the_merchant(): void
    {
        if (! $this->subject()->supports(Capability::ManualCapture)) {
            $this->markTestSkipped('The driver does not support manual capture.');
        }
        $request = $this->request(['captureMode' => CaptureMode::Manual]);
        $payment = $this->subject()->createPayment($request);
        $this->buyerPays($this->subject(), $payment->id);

        $held = $this->subject()->getPayment($payment->id);
        $this->assertNotNull($held);
        $this->assertTrue($held->status->awaitsMerchant(), "After the buyer, a manual payment waits for capture(); got {$held->status->value}.");
        $this->assertFalse($held->status->isPaid());

        $captured = $this->subject()->capture($payment->id, idempotencyKey: "capture-{$request->reference}");
        $this->assertSame(PaymentStatus::Captured, $captured->status);
        $this->assertTrue($captured->matches($request->reference, $request->amount));
    }

    public function test_hosted_checkout_completes_with_a_payment(): void
    {
        $gateway = $this->subject();
        if (! $gateway instanceof HostedCheckout) {
            $this->markTestSkipped('The driver has no hosted checkout.');
        }
        $request = $this->request();

        $checkout = $gateway->createCheckout($request);
        $this->assertSame(CheckoutStatus::Open, $checkout->status);
        $this->assertMatchesRegularExpression('#^https?://#', (string) $checkout->url);
        $this->assertSame($checkout->id, $gateway->getCheckout($checkout->id)?->id);

        $this->buyerPays($gateway, $checkout->id);

        $done = $gateway->getCheckout($checkout->id);
        $this->assertSame(CheckoutStatus::Completed, $done?->status);
        $this->assertNotNull($done->paymentId);
        $payment = $gateway->getPayment($done->paymentId);
        if ($payment !== null && $payment->status->awaitsMerchant()) {
            $payment = $gateway->capture($payment->id, idempotencyKey: "capture-{$request->reference}");
        }
        $this->assertSame(PaymentStatus::Captured, $payment?->status);
        $this->assertTrue($payment->matches($request->reference, $request->amount));
    }

    public function test_webhooks_without_a_valid_signature_are_rejected(): void
    {
        $body = json_encode(['id' => 'evt_forged', 'type' => 'payment', 'data' => ['id' => 'x']], JSON_THROW_ON_ERROR);

        foreach ([[], ['x-signature' => 'ts=1,v1=00', 'stripe-signature' => 't=1,v1=00', 'paypal-transmission-sig' => 'AA==']] as $headers) {
            try {
                $this->subject()->parseWebhook($headers, $body);
                $this->fail('An unsigned or forged webhook must be rejected.');
            } catch (InvalidWebhookSignature) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_signed_webhook_names_its_payment(): void
    {
        $payment = $this->subject()->createPayment($this->request());
        $webhook = $this->signedWebhook($this->subject(), $payment);
        if ($webhook === null) {
            $this->markTestSkipped('This test case produces no signed webhook synchronously.');
        }

        $event = $this->subject()->parseWebhook($webhook[0], $webhook[1]);
        $this->assertSame($this->subject()->name(), $event->gateway);
        $this->assertNotSame('', $event->id);
        $this->assertSame($payment->id, $this->subject()->paymentIdForEvent($event));
    }
}
