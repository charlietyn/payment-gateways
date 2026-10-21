<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ronu\PaymentGateways\Drivers\MercadoPago\MercadoPagoGateway;
use Ronu\PaymentGateways\Drivers\PayPal\PayPalGateway;
use Ronu\PaymentGateways\Drivers\Stripe\StripeGateway;
use Ronu\PaymentGateways\EventType;
use Ronu\PaymentGateways\Exceptions\InvalidWebhookSignature;
use Ronu\PaymentGateways\GatewayFactory;
use Ronu\PaymentGateways\Webhooks\CacheDeduplicator;
use Ronu\PaymentGateways\Webhooks\InMemoryDeduplicator;
use Ronu\PaymentGateways\WebhookEvent;

/** One vocabulary for three providers, and redeliveries dropped. */
final class WebhookEventsTest extends TestCase
{
    public function test_stripe_types(): void
    {
        foreach ([
            'payment_intent.succeeded' => EventType::PaymentCaptured, 'payment_intent.amount_capturable_updated' => EventType::PaymentAuthorized,
            'payment_intent.payment_failed' => EventType::PaymentFailed, 'payment_intent.canceled' => EventType::PaymentCanceled,
            'charge.refunded' => EventType::PaymentRefunded, 'refund.updated' => EventType::PaymentRefunded,
            'charge.dispute.created' => EventType::DisputeOpened, 'charge.dispute.closed' => EventType::DisputeClosed,
            'checkout.session.completed' => EventType::CheckoutCompleted, 'checkout.session.expired' => EventType::CheckoutExpired,
            'payment_intent.requires_action' => EventType::PaymentUpdated, 'customer.created' => EventType::Unknown,
        ] as $type => $kind) {
            $this->assertSame($kind, StripeGateway::eventType($type), $type);
        }
    }

    public function test_paypal_types(): void
    {
        foreach ([
            'CHECKOUT.ORDER.APPROVED' => EventType::PaymentApproved, 'PAYMENT.CAPTURE.COMPLETED' => EventType::PaymentCaptured,
            'PAYMENT.AUTHORIZATION.CREATED' => EventType::PaymentAuthorized, 'PAYMENT.AUTHORIZATION.VOIDED' => EventType::PaymentCanceled,
            'PAYMENT.CAPTURE.DENIED' => EventType::PaymentFailed, 'PAYMENT.CAPTURE.REFUNDED' => EventType::PaymentRefunded,
            'CUSTOMER.DISPUTE.CREATED' => EventType::DisputeOpened, 'CUSTOMER.DISPUTE.RESOLVED' => EventType::DisputeClosed,
            'PAYMENT.CAPTURE.PENDING' => EventType::PaymentUpdated, 'BILLING.SUBSCRIPTION.CREATED' => EventType::Unknown,
        ] as $type => $kind) {
            $this->assertSame($kind, PayPalGateway::eventType($type), $type);
        }
    }

    public function test_mercado_pago_types(): void
    {
        foreach ([
            ['order', 'order.processed', EventType::PaymentCaptured], ['order', 'order.refunded', EventType::PaymentRefunded],
            ['order', 'order.expired', EventType::PaymentCanceled], ['order', 'order.failed', EventType::PaymentFailed],
            ['order', 'order.updated', EventType::PaymentUpdated],                      // what Plaza sends
            ['payment', 'payment.updated', EventType::PaymentUpdated], ['chargebacks', null, EventType::DisputeOpened],
            ['merchant_order', null, EventType::Unknown],
        ] as [$type, $action, $kind]) {
            $this->assertSame($kind, MercadoPagoGateway::eventType($type, $action), "{$type}/{$action}");
        }
    }

    public function test_parsed_events_carry_the_kind(): void
    {
        $ts = time();
        $body = '{"id":"evt_1","type":"charge.dispute.created","data":{"object":{"id":"dp_1","object":"dispute","payment_intent":"pi_1"}}}';
        $event = GatewayFactory::make('stripe', ['credentials' => ['secret_key' => 'sk_test_1'], 'webhooks' => ['secret' => 'w']])
            ->parseWebhook(['stripe-signature' => "t={$ts},v1=" . hash_hmac('sha256', "{$ts}.{$body}", 'w')], $body);

        $this->assertSame([EventType::DisputeOpened, 'pi_1'], [$event->kind, $event->paymentId]);
    }

    public function test_paypal_can_verify_through_its_api(): void
    {
        $body = '{"id":"WH-1","event_type":"CHECKOUT.ORDER.APPROVED","resource_type":"checkout-order","resource":{"id":"O1","supplementary_data":{}}}';
        $headers = ['paypal-transmission-id' => 't-1', 'paypal-transmission-time' => gmdate('Y-m-d\TH:i:s\Z'), 'paypal-transmission-sig' => 'c2ln',
            'paypal-cert-url' => 'https://api.paypal.com/v1/notifications/certs/CERT-1', 'paypal-auth-algo' => 'SHA256withRSA'];
        $http = new MockHttp([MockHttp::json(200, ['verification_status' => 'SUCCESS']), MockHttp::json(200, ['verification_status' => 'FAILURE'])]);
        $gateway = GatewayFactory::make('paypal', [
            'credentials' => ['access_token' => 'A21'], 'webhooks' => ['id' => 'WH-ID', 'verification' => 'remote'],
        ], $http->client);

        $event = $gateway->parseWebhook($headers, $body);

        $this->assertSame('https://api-m.paypal.com/v1/notifications/verify-webhook-signature', (string) $http->request(0)->getUri());
        $sent = (string) $http->request(0)->getBody();
        $this->assertStringContainsString('"webhook_id":"WH-ID"', $sent);
        $this->assertStringContainsString('"supplementary_data":{}', $sent, 'empty objects survive the round trip');
        $this->assertSame([EventType::PaymentApproved, 'O1'], [$event->kind, $event->paymentId]);

        $this->expectException(InvalidWebhookSignature::class);
        $gateway->parseWebhook($headers, $body);
    }

    public function test_redeliveries_are_dropped_after_the_handler_succeeded(): void
    {
        $event = new WebhookEvent('stripe', 'evt_1', 't', 'payment_intent', 'pi_1', 'pi_1', []);
        $sameIdOtherGateway = new WebhookEvent('paypal', 'evt_1', 't', 'x', 'x', null, []);
        $cache = new ArrayCache();

        foreach ([new InMemoryDeduplicator(), new CacheDeduplicator($cache), new CacheDeduplicator($cache)] as $i => $events) {
            $this->assertSame($i === 2, $events->alreadyProcessed($event), "store {$i}");   // the third shares the second's cache
            $events->markProcessed($event);
            $this->assertTrue($events->alreadyProcessed($event));
            $this->assertFalse($events->alreadyProcessed($sameIdOtherGateway));
        }
    }
}
