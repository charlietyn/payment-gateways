<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Tests\Unit;

use Closure;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ronu\PaymentGateways\CaptureMode;
use Ronu\PaymentGateways\Contracts\Gateway;
use Ronu\PaymentGateways\CreatePayment;
use Ronu\PaymentGateways\Drivers\MercadoPago\MercadoPagoGateway;
use Ronu\PaymentGateways\EventType;
use Ronu\PaymentGateways\Exceptions\GatewayException;
use Ronu\PaymentGateways\Exceptions\InvalidWebhookSignature;
use Ronu\PaymentGateways\GatewayFactory;
use Ronu\PaymentGateways\Laravel\PaymentGatewaysManager;
use Ronu\PaymentGateways\Laravel\PaymentGatewaysServiceProvider;
use Ronu\PaymentGateways\Money;
use Ronu\PaymentGateways\PaymentMethod;
use Ronu\PaymentGateways\PaymentStatus;
use Ronu\PaymentGateways\Services;
use Ronu\PaymentGateways\Testing\FakeMercadoPago;

/**
 * FakeMercadoPago itself, and through it the Orders state table a consumer relies on
 * to fulfill, expire or refund (the states Plaza and Mercado Pago return).
 */
final class FakeMercadoPagoTest extends TestCase
{
    private FakeMercadoPago $fake;

    protected function setUp(): void
    {
        $this->fake = new FakeMercadoPago();
    }

    private function gateway(string $preset = 'plaza'): MercadoPagoGateway
    {
        /** @var MercadoPagoGateway */
        return GatewayFactory::make($preset, [
            'credentials' => ['access_token' => 'TEST-1'],
            'webhooks' => ['secret' => 'whsec'],
            'emulator' => ['api_url' => 'http://plaza.test'],
        ], new Services($this->fake->client(), sleep: static fn () => null));
    }

    private function request(array $overrides = []): CreatePayment
    {
        return new CreatePayment(...$overrides + ['amount' => Money::fromDecimal('1000', 'ARS'), 'reference' => 'R-1', 'idempotencyKey' => 'k-' . bin2hex(random_bytes(4))]);
    }

    /**
     * Each row: how the order got there → Mercado Pago status/detail → what the consumer sees.
     *
     * @return array<string, array{Closure, string, string, PaymentStatus}>
     */
    public static function states(): array
    {
        $ticket = fn (FakeMercadoPago $f, MercadoPagoGateway $g): string => $g->createPayment(new CreatePayment(Money::of(100_000, 'ARS'), 'R', 'k-t'))->id;
        $paid = function (FakeMercadoPago $f, MercadoPagoGateway $g) use ($ticket): string {
            $f->pay($id = $ticket($f, $g));

            return $id;
        };
        $after = fn (string $event) => function (FakeMercadoPago $f, MercadoPagoGateway $g) use ($ticket, $paid, $event): string {
            $id = $event === 'chargeBack' ? $paid($f, $g) : $ticket($f, $g);
            $f->{$event}($id);

            return $id;
        };

        return [
            'waiting for the buyer' => [$ticket, 'action_required', 'waiting_payment', PaymentStatus::RequiresAction],
            'paid' => [$paid, 'processed', 'accredited', PaymentStatus::Captured],
            'card held for capture' => [fn ($f, $g) => $g->createPayment(new CreatePayment(Money::of(100_000, 'ARS'), 'R', 'k-m', captureMode: CaptureMode::Manual,
                paymentMethod: PaymentMethod::card('tok', 'visa')))->id, 'action_required', 'waiting_capture', PaymentStatus::Authorized],
            'canceled unpaid' => [fn ($f, $g) => $g->cancel($ticket($f, $g), 'c')->id, 'canceled', 'canceled', PaymentStatus::Canceled],
            'expired unpaid' => [$after('expire'), 'expired', 'expired', PaymentStatus::Canceled],
            'declined' => [$after('decline'), 'failed', 'failed', PaymentStatus::Failed],
            'partly refunded' => [fn ($f, $g) => $g->refund($paid($f, $g), Money::of(10_000, 'ARS'), 'r')->paymentId, 'processed', 'partially_refunded', PaymentStatus::PartiallyRefunded],
            'refunded' => [fn ($f, $g) => $g->refund($paid($f, $g), null, 'r')->paymentId, 'refunded', 'refunded', PaymentStatus::Refunded],
            'charged back' => [$after('chargeBack'), 'charged_back', 'in_process', PaymentStatus::Disputed],
        ];
    }

    #[DataProvider('states')]
    public function test_state_table(Closure $arrange, string $status, string $detail, PaymentStatus $expected): void
    {
        $gateway = $this->gateway();
        $payment = $gateway->getPayment($arrange($this->fake, $gateway));

        $this->assertSame([$status, $detail], [$payment?->providerStatus, $payment?->providerStatusDetail]);
        $this->assertSame($expected, $payment->status);
    }

    public function test_a_captured_order_is_not_canceled_and_the_error_says_why(): void
    {
        $gateway = $this->gateway();
        $id = $gateway->createPayment($this->request())->id;
        $this->fake->pay($id);

        try {
            $gateway->cancel($id, 'cancel-1');
            $this->fail('expected invalid_state');
        } catch (GatewayException $e) {
            $this->assertFalse($e->retryable);
            $this->assertSame(400, $e->httpStatus);
            $this->assertSame('invalid_state', $e->providerCode);
        }
        $this->assertSame(PaymentStatus::Captured, $gateway->getPayment($id)?->status);
    }

    public function test_a_card_with_a_token_is_processed_at_once_and_the_token_is_not_kept(): void
    {
        $payment = $this->gateway('mercadopago')->createPayment($this->request(['paymentMethod' => PaymentMethod::card('card-token-1', 'master', 3)]));

        $this->assertSame(PaymentStatus::Captured, $payment->status);
        $this->assertSame('https://api.mercadopago.com/v1/orders', (string) $this->fake->requests[0]->getUri());
        $this->assertStringNotContainsString('card-token-1', (string) json_encode($this->fake->order($payment->id)));
    }

    public function test_a_ticket_on_real_mercado_pago_points_to_its_ticket_url(): void
    {
        $payment = $this->gateway('mercadopago')->createPayment($this->request());

        $this->assertSame(PaymentStatus::RequiresAction, $payment->status);
        $this->assertSame("https://fake-mercadopago.test/tickets/{$payment->id}", $payment->nextAction?->url);
    }

    public function test_idempotency_replays_and_conflicts(): void
    {
        $gateway = $this->gateway();
        $first = $gateway->createPayment($this->request(['idempotencyKey' => 'same']));

        $this->assertSame($first->id, $gateway->createPayment($this->request(['idempotencyKey' => 'same']))->id);
        $this->assertCount(1, $this->fake->orders());

        try {
            $gateway->createPayment($this->request(['idempotencyKey' => 'same', 'amount' => Money::fromDecimal('5', 'ARS')]));
            $this->fail('expected an idempotency conflict');
        } catch (GatewayException $e) {
            $this->assertSame([409, 'idempotency_error', false], [$e->httpStatus, $e->providerCode, $e->retryable]);
        }
    }

    public function test_a_failure_is_retried_with_the_same_key_and_creates_one_order(): void
    {
        $this->fake->failNext(503);
        $payment = $this->gateway()->createPayment($this->request(['idempotencyKey' => 'retry-1']));

        $this->assertCount(2, $this->fake->requests);
        $this->assertSame(['retry-1', 'retry-1'], array_map(fn ($r) => $r->getHeaderLine('X-Idempotency-Key'), $this->fake->requests));
        $this->assertSame([$payment->id], array_keys($this->fake->orders()));
    }

    public function test_refunds_check_the_transaction_and_the_remaining_amount(): void
    {
        $gateway = $this->gateway();
        $id = $gateway->createPayment($this->request())->id;
        $this->fake->pay($id);

        $this->assertSame(PaymentStatus::PartiallyRefunded, $gateway->refund($id, Money::fromDecimal('400', 'ARS'), 'r-1')->payment?->status);
        try {
            $gateway->refund($id, Money::fromDecimal('700', 'ARS'), 'r-2');
            $this->fail('expected invalid_amount');
        } catch (GatewayException $e) {
            $this->assertSame('invalid_amount', $e->providerCode);
        }
        $rest = $gateway->refund($id, null, 'r-3');
        $this->assertSame(60_000, $rest->amount->minor);
        $this->assertSame(PaymentStatus::Refunded, $rest->payment?->status);
        $this->assertCount(2, $this->fake->order($id)['transactions']['refunds']);
    }

    public function test_signed_webhooks_verify_and_forged_ones_do_not(): void
    {
        $gateway = $this->gateway();
        $id = $gateway->createPayment($this->request())->id;

        [$headers, $body] = $this->fake->signedWebhook($id, 'whsec', 'order.processed');
        $event = $gateway->parseWebhook($headers, $body);
        $this->assertSame($id, $gateway->paymentIdForEvent($event));
        $this->assertSame(EventType::PaymentCaptured, $event->kind);

        $this->expectException(InvalidWebhookSignature::class);
        $gateway->parseWebhook(...$this->fake->signedWebhook($id, 'another-secret'));
    }

    public function test_requests_without_a_token_are_unauthorized(): void
    {
        $response = $this->fake->client()->post('http://x.test/v1/orders', ['http_errors' => false, 'json' => []]);

        $this->assertSame(401, $response->getStatusCode());
    }

    /** `emulator.api_url` with or without the profile reaches the same URLs. */
    public function test_the_emulator_url_may_already_end_with_the_profile(): void
    {
        foreach (['http://localhost:8090', 'http://localhost:8090/', 'http://localhost:8090/plaza', 'http://localhost:8090/plaza/'] as $url) {
            $fake = new FakeMercadoPago();
            $gateway = GatewayFactory::make('plaza', ['credentials' => ['access_token' => 'TEST-1'], 'emulator' => ['api_url' => $url]], $fake->client());
            $payment = $gateway->createPayment($this->request());

            $this->assertSame('http://localhost:8090/plaza/v1/orders', (string) $fake->requests[0]->getUri(), $url);
            $this->assertSame("http://localhost:8090/plaza/checkout/{$payment->id}", $payment->nextAction?->url, $url);
        }

        $fake = new FakeMercadoPago();
        $payment = GatewayFactory::make('plaza', ['credentials' => ['access_token' => 'TEST-1'],
            'emulator' => ['api_url' => 'http://localhost:8090/plaza', 'checkout_url' => 'http://localhost:3101/plaza']], $fake->client())->createPayment($this->request());
        $this->assertSame("http://localhost:3101/plaza/checkout/{$payment->id}", $payment->nextAction?->url);
    }

    /** In Laravel the gateways use a client bound as payment-gateways.http. */
    public function test_laravel_gateways_use_the_bound_http_client(): void
    {
        $app = new Container();
        $app->instance('config', new Repository(['payment-gateways' => [
            'default' => 'mercadopago',
            'gateways' => ['mercadopago' => ['preset' => 'plaza', 'credentials' => ['access_token' => 'TEST-1'], 'emulator' => ['api_url' => 'http://localhost:8090']]],
        ]]));
        $app->instance(PaymentGatewaysServiceProvider::HTTP, $this->fake->client());
        (new PaymentGatewaysServiceProvider($app))->register();

        $payment = $app->make(Gateway::class)->createPayment($this->request());

        $this->assertSame($payment->id, array_key_first($this->fake->orders()));
        $this->assertSame($payment->id, $app->make(PaymentGatewaysManager::class)->gateway()->getPayment($payment->id)?->id);
    }
}
