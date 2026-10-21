<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Drivers\Stripe;

use GuzzleHttp\ClientInterface;
use Ronu\PaymentGateways\CaptureMode;
use Ronu\PaymentGateways\Contracts\Gateway;
use Ronu\PaymentGateways\CreatePayment;
use Ronu\PaymentGateways\Exceptions\ConfigurationException;
use Ronu\PaymentGateways\Exceptions\GatewayException;
use Ronu\PaymentGateways\Exceptions\InvalidWebhookSignature;
use Ronu\PaymentGateways\Http\Transport;
use Ronu\PaymentGateways\Money;
use Ronu\PaymentGateways\NextAction;
use Ronu\PaymentGateways\Payment;
use Ronu\PaymentGateways\PaymentStatus;
use Ronu\PaymentGateways\Refund;
use Ronu\PaymentGateways\Support\Headers;
use Ronu\PaymentGateways\WebhookEvent;

/**
 * Stripe PaymentIntents + Refunds. Preset `vector` points it at the Payment
 * Emulator Lab; preset `stripe` at api.stripe.com.
 *
 * Requests are form-encoded, as the real API requires.
 *
 * Config:
 *  - base_url, secret_key (sk_test_… / sk_live_…), webhook_secret (whsec_…)
 *  - confirm_on_create: true for the emulator (its hosted page is the buyer step,
 *    reached through next_action.redirect_to_url). False against real Stripe, where
 *    the buyer confirms with Stripe.js using Payment::$clientSecret.
 *  - scenario: emulator only — sent as X-Emulator-Scenario
 *  - webhook_tolerance_seconds (300, Stripe's default)
 */
final class StripePaymentIntentsGateway implements Gateway
{
    private Transport $http;

    /** @param  array<string, mixed>  $config */
    public function __construct(private readonly array $config, ?ClientInterface $client = null)
    {
        foreach (['base_url', 'secret_key'] as $key) {
            if (empty($config[$key])) {
                throw new ConfigurationException("stripe: '{$key}' is required.");
            }
        }

        $this->http = new Transport(
            (string) $config['base_url'],
            $client,
            (float) ($config['timeout'] ?? 15),
            (float) ($config['connect_timeout'] ?? 5),
            fn (array $body): ?string => isset($body['error']['code']) ? (string) $body['error']['code']
                : (isset($body['error']['type']) ? (string) $body['error']['type'] : null),
        );
    }

    public function name(): string
    {
        return 'stripe';
    }

    public function createPayment(CreatePayment $request): Payment
    {
        $confirm = (bool) ($this->config['confirm_on_create'] ?? false);
        $form = array_filter([
            'amount' => $request->amount->minor,
            'currency' => strtolower($request->amount->currency),
            'capture_method' => $request->captureMode === CaptureMode::Manual ? 'manual' : 'automatic',
            'confirm' => $confirm ? 'true' : 'false',
            'description' => $request->description,
            'receipt_email' => $request->payer?->email,
            'return_url' => $confirm ? $request->returnUrl : null,
            'metadata' => ['order_id' => $request->reference] + $request->metadata,
        ], fn ($value) => $value !== null && $value !== '');

        $headers = $this->headers($request->idempotencyKey);
        if (! empty($this->config['scenario'])) {
            $headers['X-Emulator-Scenario'] = (string) $this->config['scenario'];
        }

        return $this->toPayment($this->http->request('POST', '/v1/payment_intents', $headers, form: $form) ?? []);
    }

    public function getPayment(string $paymentId): ?Payment
    {
        if (! preg_match('/^pi_[A-Za-z0-9]{1,250}$/', $paymentId)) {
            return null;
        }

        $intent = $this->http->request('GET', '/v1/payment_intents/' . $paymentId, $this->headers(), allowNotFound: true);

        return $intent === null ? null : $this->toPayment($intent);
    }

    public function capture(string $paymentId, ?Money $amount = null, ?string $idempotencyKey = null): Payment
    {
        $form = $amount !== null ? ['amount_to_capture' => $amount->minor] : [];

        return $this->toPayment($this->http->request('POST', '/v1/payment_intents/' . $paymentId . '/capture',
            $this->headers($idempotencyKey ?? "capture-{$paymentId}"), form: $form) ?? []);
    }

    public function cancel(string $paymentId, ?string $idempotencyKey = null): Payment
    {
        return $this->toPayment($this->http->request('POST', '/v1/payment_intents/' . $paymentId . '/cancel',
            $this->headers($idempotencyKey ?? "cancel-{$paymentId}"), form: []) ?? []);
    }

    public function refund(string $paymentId, ?Money $amount, string $idempotencyKey): Refund
    {
        $form = ['payment_intent' => $paymentId] + ($amount !== null ? ['amount' => $amount->minor] : []);
        $refund = $this->http->request('POST', '/v1/refunds', $this->headers($idempotencyKey), form: $form) ?? [];

        if (! isset($refund['id'])) {
            throw new GatewayException('Stripe returned a refund without id.');
        }

        // A PaymentIntent does not reflect refunds (they live on the charge): the
        // payment is read back as it is, still `captured`.
        return new Refund(
            id: (string) $refund['id'],
            amount: Money::of((int) ($refund['amount'] ?? 0), (string) ($refund['currency'] ?? 'usd')),
            status: (string) ($refund['status'] ?? 'pending'),
            paymentId: $paymentId,
            payment: $this->getPayment($paymentId),
            raw: $refund,
        );
    }

    /** `stripe-signature: t=<ts>,v1=<hmac>` over `<ts>.<raw body>`. Any v1 may match (secret rotation). */
    public function parseWebhook(array $headers, string $rawBody): WebhookEvent
    {
        $secret = (string) ($this->config['webhook_secret'] ?? '');
        if ($secret === '') {
            throw new InvalidWebhookSignature(InvalidWebhookSignature::NOT_CONFIGURED);
        }

        $header = Headers::get($headers, 'stripe-signature') ?? throw new InvalidWebhookSignature(InvalidWebhookSignature::MALFORMED);
        $parts = Headers::parseSignature($header);
        $ts = filter_var($parts['t'][0] ?? null, FILTER_VALIDATE_INT);
        $candidates = $parts['v1'] ?? [];
        if ($ts === false || $candidates === []) {
            throw new InvalidWebhookSignature(InvalidWebhookSignature::MALFORMED);
        }

        if (abs(time() - $ts) > (int) ($this->config['webhook_tolerance_seconds'] ?? 300)) {
            throw new InvalidWebhookSignature(InvalidWebhookSignature::OUTSIDE_TOLERANCE);
        }

        $expected = hash_hmac('sha256', "{$ts}.{$rawBody}", $secret);
        $valid = array_reduce($candidates, fn (bool $ok, string $v) => $ok || hash_equals($expected, strtolower($v)), false);
        if (! $valid) {
            throw new InvalidWebhookSignature(InvalidWebhookSignature::MISMATCH);
        }

        $event = json_decode($rawBody, true);
        if (! is_array($event) || ! isset($event['id'], $event['type'])) {
            throw new InvalidWebhookSignature(InvalidWebhookSignature::MALFORMED);
        }

        $object = (array) ($event['data']['object'] ?? []);
        $kind = (string) ($object['object'] ?? 'unknown');
        $paymentId = match ($kind) {
            'payment_intent' => isset($object['id']) ? (string) $object['id'] : null,
            'charge', 'refund', 'dispute' => isset($object['payment_intent']) ? (string) $object['payment_intent'] : null,
            default => null,
        };

        return new WebhookEvent(
            gateway: $this->name(),
            id: (string) $event['id'],
            type: (string) $event['type'],
            resourceType: $kind,
            resourceId: (string) ($object['id'] ?? ''),
            paymentId: $paymentId,
            payload: $event,
        );
    }

    public function paymentIdForEvent(WebhookEvent $event): ?string
    {
        return $event->paymentId;
    }

    /** @param  array<string, mixed>  $intent */
    public static function mapStatus(array $intent): PaymentStatus
    {
        return match ($intent['status'] ?? null) {
            'requires_payment_method' => ! empty($intent['last_payment_error']) ? PaymentStatus::Failed : PaymentStatus::Created,
            'requires_confirmation' => PaymentStatus::Created,
            'requires_action' => PaymentStatus::RequiresAction,
            'processing' => PaymentStatus::Processing,
            'requires_capture' => PaymentStatus::Authorized,
            'succeeded' => PaymentStatus::Captured,
            'canceled' => PaymentStatus::Canceled,
            default => PaymentStatus::Unknown,
        };
    }

    /** @param  array<string, mixed>  $intent */
    private function toPayment(array $intent): Payment
    {
        if (! isset($intent['id'])) {
            throw new GatewayException('Stripe returned a payment intent without id.');
        }

        $currency = (string) ($intent['currency'] ?? 'usd');
        $redirect = $intent['next_action']['redirect_to_url']['url'] ?? null;
        $returnUrl = $intent['next_action']['redirect_to_url']['return_url'] ?? null;

        return new Payment(
            gateway: $this->name(),
            id: (string) $intent['id'],
            status: self::mapStatus($intent),
            amount: Money::of((int) ($intent['amount'] ?? 0), $currency),
            reference: isset($intent['metadata']['order_id']) ? (string) $intent['metadata']['order_id'] : null,
            providerStatus: isset($intent['status']) ? (string) $intent['status'] : null,
            providerStatusDetail: isset($intent['last_payment_error']['code']) ? (string) $intent['last_payment_error']['code'] : null,
            transactionId: isset($intent['latest_charge']) && is_string($intent['latest_charge']) ? $intent['latest_charge'] : null,
            capturedAmount: Money::of((int) ($intent['amount_received'] ?? 0), $currency),
            // Real Stripe sends the buyer back to return_url after the redirect; the
            // emulator's page does not, which its preset declares.
            nextAction: is_string($redirect) && $redirect !== ''
                ? NextAction::redirect($redirect, (bool) ($this->config['redirect_returns_to_merchant'] ?? true) && is_string($returnUrl) && $returnUrl !== '')
                : null,
            raw: $intent,
            clientSecret: isset($intent['client_secret']) ? (string) $intent['client_secret'] : null,
        );
    }

    /** @return array<string, string> */
    private function headers(?string $idempotencyKey = null): array
    {
        $headers = ['Authorization' => 'Bearer ' . $this->config['secret_key']];
        if (! empty($this->config['api_version'])) {
            $headers['Stripe-Version'] = (string) $this->config['api_version'];
        }

        return $idempotencyKey !== null ? $headers + ['Idempotency-Key' => $idempotencyKey] : $headers;
    }
}
