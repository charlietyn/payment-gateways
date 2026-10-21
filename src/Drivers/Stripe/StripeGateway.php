<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Drivers\Stripe;

use DateTimeImmutable;
use InvalidArgumentException;
use Ronu\PaymentGateways\Capability;
use Ronu\PaymentGateways\CaptureMode;
use Ronu\PaymentGateways\Checkout;
use Ronu\PaymentGateways\CheckoutStatus;
use Ronu\PaymentGateways\Config\GatewayConfig;
use Ronu\PaymentGateways\Contracts\Gateway;
use Ronu\PaymentGateways\Contracts\HostedCheckout;
use Ronu\PaymentGateways\CreatePayment;
use Ronu\PaymentGateways\EventType;
use Ronu\PaymentGateways\Exceptions\GatewayException;
use Ronu\PaymentGateways\Exceptions\InvalidWebhookSignature;
use Ronu\PaymentGateways\Http\Transport;
use Ronu\PaymentGateways\Money;
use Ronu\PaymentGateways\NextAction;
use Ronu\PaymentGateways\Payment;
use Ronu\PaymentGateways\PaymentStatus;
use Ronu\PaymentGateways\Refund;
use Ronu\PaymentGateways\Services;
use Ronu\PaymentGateways\Support\Headers;
use Ronu\PaymentGateways\WebhookEvent;

/**
 * Stripe PaymentIntents + Refunds + Checkout Sessions. Requests are form-encoded,
 * as the real API requires.
 *
 *  - createPayment(): a PaymentIntent. With PaymentMethod::card('pm_…') it is confirmed
 *    server-side; without one your page confirms it with Stripe.js and Payment::$clientSecret.
 *  - createCheckout(): a Checkout Session (`mode=payment`); the buyer pays on Stripe and
 *    returns to returnUrl. The PaymentIntent is in getCheckout()->paymentId afterwards.
 *
 * On the emulator (Vector) there are no Checkout Sessions and no Elements: every intent is
 * confirmed on create and Vector's hosted page is the buyer step (next_action.redirect_to_url).
 *
 * Config:
 *  - credentials.secret_key (sk_test_… / sk_live_…)
 *  - webhooks.secret (whsec_…), webhooks.tolerance_seconds (300, Stripe's default)
 *  - options.api_version: pin it (Stripe-Version) so an account upgrade cannot change the wire
 */
final class StripeGateway implements Gateway, HostedCheckout
{
    public const HOSTS = ['sandbox' => 'https://api.stripe.com', 'live' => 'https://api.stripe.com'];

    public const EMULATOR_PROFILE = 'vector';

    private Transport $http;

    public function __construct(private readonly GatewayConfig $config, Services $services = new Services())
    {
        $config->credential('secret_key');

        $this->http = Transport::for(
            $config,
            $config->resolveBaseUrl(self::HOSTS, self::EMULATOR_PROFILE),
            $services,
            fn (array $body): ?string => isset($body['error']['code']) ? (string) $body['error']['code']
                : (isset($body['error']['type']) ? (string) $body['error']['type'] : null),
        );
    }

    public function name(): string
    {
        return 'stripe';
    }

    public function supports(Capability $capability): bool
    {
        return $capability !== Capability::OfflinePayments;
    }

    public function createPayment(CreatePayment $request): Payment
    {
        $token = $request->paymentMethod?->token;
        // Real Stripe only confirms server-side with a payment method; the emulator's
        // hosted page plays the buyer, so there every intent is confirmed.
        $confirm = $token !== null || $this->config->isEmulator();

        $form = array_filter([
            'amount' => $request->amount->minor,
            'currency' => strtolower($request->amount->currency),
            'capture_method' => $request->captureMode === CaptureMode::Manual ? 'manual' : 'automatic',
            'confirm' => $confirm ? 'true' : 'false',
            'payment_method' => $token,
            'description' => $request->description,
            'receipt_email' => $request->payer?->email,
            'return_url' => $confirm ? $request->returnUrl : null,
            'metadata' => $this->metadata($request),
        ], fn ($value) => $value !== null && $value !== '');

        $headers = $this->headers($request->idempotencyKey) + $this->config->emulatorHeaders();

        return $this->toPayment($this->http->request('POST', '/v1/payment_intents', $headers, form: $form) ?? []);
    }

    /**
     * A Checkout Session for one line: the amount, named by the description. Add
     * `{CHECKOUT_SESSION_ID}` to returnUrl to get the session id back on your page.
     */
    public function createCheckout(CreatePayment $request): Checkout
    {
        if ($this->config->isEmulator()) {
            return Checkout::forPayment($this->createPayment($request));
        }
        if ($request->returnUrl === null) {
            throw new InvalidArgumentException('Stripe Checkout needs returnUrl (success_url).');
        }

        $form = array_filter([
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($request->amount->currency),
                    'unit_amount' => $request->amount->minor,
                    'product_data' => ['name' => $request->description ?? $request->reference],
                ],
            ]],
            'success_url' => $request->returnUrl,
            'cancel_url' => $request->cancelUrl,
            'client_reference_id' => $request->reference,
            'customer_email' => $request->payer?->email,
            'metadata' => $this->metadata($request),
            'payment_intent_data' => array_filter([
                'capture_method' => $request->captureMode === CaptureMode::Manual ? 'manual' : null,
                'description' => $request->description,
                'metadata' => $this->metadata($request),
            ]),
            'expires_at' => $request->expiresAt?->getTimestamp(),
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);

        return $this->toCheckout($this->http->request('POST', '/v1/checkout/sessions', $this->headers($request->idempotencyKey), form: $form) ?? []);
    }

    public function getCheckout(string $checkoutId): ?Checkout
    {
        if ($this->config->isEmulator()) {
            $payment = $this->getPayment($checkoutId);

            return $payment === null ? null : Checkout::forPayment($payment);
        }
        if (! preg_match('/^cs_[A-Za-z0-9_]{1,250}$/', $checkoutId)) {
            return null;
        }

        $session = $this->http->request('GET', '/v1/checkout/sessions/' . $checkoutId, $this->headers(), allowNotFound: true);

        return $session === null ? null : $this->toCheckout($session);
    }

    public function getPayment(string $paymentId): ?Payment
    {
        if (! preg_match('/^pi_[A-Za-z0-9]{1,250}$/', $paymentId)) {
            return null;
        }

        // The charge holds refunds and disputes; Vector ignores `expand` and keeps the id.
        $intent = $this->http->request('GET', '/v1/payment_intents/' . $paymentId . '?expand%5B%5D=latest_charge', $this->headers(), allowNotFound: true);

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

        // Read back with the charge expanded, so the payment says refunded / partially_refunded.
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
        $secret = (string) $this->config->webhook('secret', '');
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

        if (abs(time() - $ts) > (int) $this->config->webhook('tolerance_seconds', 300)) {
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
            'charge', 'refund', 'dispute', 'checkout.session' => is_string($object['payment_intent'] ?? null) ? $object['payment_intent'] : null,
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
            kind: self::eventType((string) $event['type']),
        );
    }

    public function paymentIdForEvent(WebhookEvent $event): ?string
    {
        return $event->paymentId;
    }

    /** Event type → canonical event. */
    public static function eventType(string $type): EventType
    {
        return match ($type) {
            'payment_intent.succeeded', 'charge.succeeded', 'charge.captured' => EventType::PaymentCaptured,
            'payment_intent.amount_capturable_updated' => EventType::PaymentAuthorized,
            'payment_intent.payment_failed', 'charge.failed', 'checkout.session.async_payment_failed' => EventType::PaymentFailed,
            'payment_intent.canceled', 'charge.expired' => EventType::PaymentCanceled,
            'charge.refunded', 'refund.created', 'refund.updated', 'charge.refund.updated' => EventType::PaymentRefunded,
            'charge.dispute.created' => EventType::DisputeOpened,
            'charge.dispute.closed' => EventType::DisputeClosed,
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' => EventType::CheckoutCompleted,
            'checkout.session.expired' => EventType::CheckoutExpired,
            default => str_starts_with($type, 'payment_intent.') || str_starts_with($type, 'charge.') ? EventType::PaymentUpdated : EventType::Unknown,
        };
    }

    /** @param  array<string, mixed>  $intent  With `latest_charge` expanded, refunds and disputes count. */
    public static function mapStatus(array $intent): PaymentStatus
    {
        $charge = is_array($intent['latest_charge'] ?? null) ? $intent['latest_charge'] : null;
        if (($intent['status'] ?? null) === 'succeeded' && $charge !== null) {
            $refunded = (int) ($charge['amount_refunded'] ?? 0);

            return match (true) {
                ! empty($charge['disputed']) => PaymentStatus::Disputed,
                ! empty($charge['refunded']) || ($refunded > 0 && $refunded >= (int) ($charge['amount_captured'] ?? $intent['amount'] ?? 0)) => PaymentStatus::Refunded,
                $refunded > 0 => PaymentStatus::PartiallyRefunded,
                default => PaymentStatus::Captured,
            };
        }

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

    /** @return array<string, string> */
    private function metadata(CreatePayment $request): array
    {
        return ['order_id' => $request->reference] + $request->metadata;
    }

    /** @param  array<string, mixed>  $intent */
    private function toPayment(array $intent): Payment
    {
        if (! isset($intent['id'])) {
            throw new GatewayException('Stripe returned a payment intent without id.');
        }

        $currency = (string) ($intent['currency'] ?? 'usd');
        $charge = $intent['latest_charge'] ?? null;
        $chargeId = is_array($charge) ? ($charge['id'] ?? null) : $charge;
        $refunded = is_array($charge) ? (int) ($charge['amount_refunded'] ?? 0) : 0;
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
            transactionId: is_string($chargeId) ? $chargeId : null,
            capturedAmount: Money::of((int) ($intent['amount_received'] ?? 0), $currency),
            refundedAmount: $refunded > 0 ? Money::of($refunded, $currency) : null,
            // Real Stripe sends the buyer back to return_url after the redirect; the
            // emulator's page does not.
            nextAction: is_string($redirect) && $redirect !== ''
                ? NextAction::redirect($redirect, ! $this->config->isEmulator() && is_string($returnUrl) && $returnUrl !== '')
                : null,
            raw: $intent,
            clientSecret: isset($intent['client_secret']) ? (string) $intent['client_secret'] : null,
        );
    }

    /** @param  array<string, mixed>  $session */
    private function toCheckout(array $session): Checkout
    {
        if (! isset($session['id'])) {
            throw new GatewayException('Stripe returned a checkout session without id.');
        }

        $status = match ($session['status'] ?? null) {
            'complete' => CheckoutStatus::Completed,
            'expired' => CheckoutStatus::Expired,
            default => CheckoutStatus::Open,
        };
        $intent = $session['payment_intent'] ?? null;
        $intent = is_array($intent) ? ($intent['id'] ?? null) : $intent;
        unset($session['client_secret']);

        return new Checkout(
            gateway: $this->name(),
            id: (string) $session['id'],
            status: $status,
            url: $status === CheckoutStatus::Open && is_string($session['url'] ?? null) ? $session['url'] : null,
            returnsToMerchant: true,
            paymentId: is_string($intent) ? $intent : null,
            reference: isset($session['client_reference_id']) ? (string) $session['client_reference_id'] : null,
            expiresAt: isset($session['expires_at']) && is_int($session['expires_at']) ? (new DateTimeImmutable())->setTimestamp($session['expires_at']) : null,
            raw: $session,
        );
    }

    /** @return array<string, string> */
    private function headers(?string $idempotencyKey = null): array
    {
        $headers = ['Authorization' => 'Bearer ' . $this->config->credential('secret_key')];
        $version = (string) $this->config->option('api_version', '');
        if ($version !== '') {
            $headers['Stripe-Version'] = $version;
        }

        return $idempotencyKey !== null ? $headers + ['Idempotency-Key' => $idempotencyKey] : $headers;
    }
}
