<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Drivers\MercadoPago;

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
 * Mercado Pago Orders API (`/v1/orders`). Preset `plaza` points it at the
 * Payment Emulator Lab; preset `mercadopago` at api.mercadopago.com.
 *
 * Config:
 *  - base_url, access_token (TEST-… / APP_USR-…), webhook_secret
 *  - currency: the site currency (ARS for MLA). Orders has no currency field on create.
 *  - payment_method: ['id' => 'pagofacil', 'type' => 'ticket'] — the order waits for the buyer
 *  - checkout_url: emulator only — Plaza serves the buyer page at {checkout_url}/plaza/checkout/{id}
 *  - scenario: emulator only — sent as X-Emulator-Scenario
 *  - webhook_tolerance_seconds (600, the window Mercado Pago documents)
 */
final class MercadoPagoOrdersGateway implements Gateway
{
    private Transport $http;

    /** @param  array<string, mixed>  $config */
    public function __construct(private readonly array $config, ?ClientInterface $client = null)
    {
        foreach (['base_url', 'access_token'] as $key) {
            if (empty($config[$key])) {
                throw new ConfigurationException("mercadopago: '{$key}' is required.");
            }
        }

        $this->http = new Transport(
            (string) $config['base_url'],
            $client,
            (float) ($config['timeout'] ?? 15),
            (float) ($config['connect_timeout'] ?? 5),
            fn (array $body): ?string => isset($body['error']) ? (string) $body['error'] : null,
        );
    }

    public function name(): string
    {
        return 'mercadopago';
    }

    public function createPayment(CreatePayment $request): Payment
    {
        $amount = $request->amount->toDecimal();
        $method = (array) ($this->config['payment_method'] ?? ['id' => 'pagofacil', 'type' => 'ticket']);
        $payer = $request->payer;

        $body = array_filter([
            'type' => 'online',
            'processing_mode' => 'automatic',
            'capture_mode' => $request->captureMode === CaptureMode::Manual ? 'manual' : 'automatic',
            'total_amount' => $amount,
            'external_reference' => $request->reference,
            'description' => $request->description,
            'payer' => $payer ? array_filter([
                'email' => $payer->email,
                'first_name' => $payer->firstName,
                'last_name' => $payer->lastName,
                'identification' => $payer->documentNumber
                    ? ['type' => $payer->documentType ?? 'DNI', 'number' => $payer->documentNumber]
                    : null,
            ]) : null,
            'transactions' => ['payments' => [[
                'amount' => $amount,
                'payment_method' => ['id' => (string) ($method['id'] ?? 'pagofacil'), 'type' => (string) ($method['type'] ?? 'ticket')],
            ]]],
        ], fn ($value) => $value !== null && $value !== []);

        $order = $this->http->request('POST', '/v1/orders', $this->writeHeaders($request->idempotencyKey, true), $body);

        return $this->toPayment($order ?? []);
    }

    public function getPayment(string $paymentId): ?Payment
    {
        if (! preg_match('/^[A-Za-z0-9_-]{1,128}$/', $paymentId)) {
            return null;
        }

        $order = $this->http->request('GET', '/v1/orders/' . rawurlencode($paymentId), $this->readHeaders(), allowNotFound: true);

        return $order === null ? null : $this->toPayment($order);
    }

    public function capture(string $paymentId, ?Money $amount = null, ?string $idempotencyKey = null): Payment
    {
        // Orders capture is total and takes an empty body.
        if ($amount !== null) {
            $current = $this->getPayment($paymentId);
            if ($current && ! $amount->equals($current->amount)) {
                throw new GatewayException('Mercado Pago Orders only captures the full amount.');
            }
        }

        $order = $this->http->request('POST', '/v1/orders/' . rawurlencode($paymentId) . '/capture',
            $this->writeHeaders($idempotencyKey ?? "capture-{$paymentId}"), (object) []);

        return $this->toPayment($order ?? []);
    }

    public function cancel(string $paymentId, ?string $idempotencyKey = null): Payment
    {
        $order = $this->http->request('POST', '/v1/orders/' . rawurlencode($paymentId) . '/cancel',
            $this->writeHeaders($idempotencyKey ?? "cancel-{$paymentId}"), (object) []);

        return $this->toPayment($order ?? []);
    }

    public function refund(string $paymentId, ?Money $amount, string $idempotencyKey): Refund
    {
        $body = (object) [];
        if ($amount !== null) {
            // A partial refund names the transaction; `{}` refunds what remains.
            $current = $this->getPayment($paymentId) ?? throw new GatewayException("Unknown order {$paymentId}.", false, 404);
            if ($current->transactionId === null) {
                throw new GatewayException('The order has no payment transaction to refund.');
            }
            $body = ['transactions' => [['id' => $current->transactionId, 'amount' => $amount->toDecimal()]]];
        }

        $order = $this->http->request('POST', '/v1/orders/' . rawurlencode($paymentId) . '/refund', $this->writeHeaders($idempotencyKey), $body) ?? [];
        $payment = $this->toPayment($order);
        $refunds = (array) ($order['transactions']['refunds'] ?? []);
        $last = (array) (end($refunds) ?: []);

        return new Refund(
            id: (string) ($last['id'] ?? $idempotencyKey),
            amount: isset($last['amount']) ? Money::fromDecimal((string) $last['amount'], $payment->amount->currency) : ($amount ?? $payment->amount),
            status: (string) ($last['status'] ?? 'processed'),
            paymentId: $paymentId,
            payment: $payment,
            raw: $order,
        );
    }

    /**
     * Mercado Pago signs `id:<data.id>;request-id:<x-request-id>;ts:<ts>;` with HMAC-SHA256.
     * The data id is accepted as sent and lower-cased: Mercado Pago documents lower case for
     * alphanumeric ids and Plaza signs them verbatim.
     */
    public function parseWebhook(array $headers, string $rawBody): WebhookEvent
    {
        $secret = (string) ($this->config['webhook_secret'] ?? '');
        if ($secret === '') {
            throw new InvalidWebhookSignature(InvalidWebhookSignature::NOT_CONFIGURED);
        }

        $payload = json_decode($rawBody, true);
        $payload = is_array($payload) ? $payload : [];
        $dataId = (string) ($payload['data']['id'] ?? '');
        $signature = Headers::get($headers, 'x-signature');
        $requestId = Headers::get($headers, 'x-request-id');

        if ($dataId === '' || $signature === null || $requestId === null) {
            throw new InvalidWebhookSignature(InvalidWebhookSignature::MALFORMED);
        }

        $parts = Headers::parseSignature($signature);
        $ts = filter_var($parts['ts'][0] ?? null, FILTER_VALIDATE_INT);
        $provided = strtolower($parts['v1'][0] ?? '');
        if ($ts === false || $provided === '') {
            throw new InvalidWebhookSignature(InvalidWebhookSignature::MALFORMED);
        }

        if (abs(time() - $ts) > (int) ($this->config['webhook_tolerance_seconds'] ?? 600)) {
            throw new InvalidWebhookSignature(InvalidWebhookSignature::OUTSIDE_TOLERANCE);
        }

        $valid = false;
        foreach (array_unique([$dataId, strtolower($dataId)]) as $id) {
            $valid = $valid || hash_equals(hash_hmac('sha256', "id:{$id};request-id:{$requestId};ts:{$ts};", $secret), $provided);
        }
        if (! $valid) {
            throw new InvalidWebhookSignature(InvalidWebhookSignature::MISMATCH);
        }

        $type = (string) ($payload['type'] ?? $payload['topic'] ?? 'unknown');

        return new WebhookEvent(
            gateway: $this->name(),
            id: (string) ($payload['id'] ?? $requestId),
            type: (string) ($payload['action'] ?? $type),
            resourceType: $type,
            resourceId: $dataId,
            paymentId: $type === 'order' ? $dataId : null,
            payload: $payload,
        );
    }

    public function paymentIdForEvent(WebhookEvent $event): ?string
    {
        return $event->paymentId;
    }

    /** Orders API status → canonical. */
    public static function mapStatus(?string $status, ?string $detail): PaymentStatus
    {
        return match ($status) {
            'created' => PaymentStatus::Created,
            'action_required' => $detail === 'waiting_capture' ? PaymentStatus::Authorized : PaymentStatus::RequiresAction,
            'processing', 'in_process' => PaymentStatus::Processing,
            'processed' => $detail === 'partially_refunded' ? PaymentStatus::PartiallyRefunded : PaymentStatus::Captured,
            'refunded' => PaymentStatus::Refunded,
            'canceled', 'cancelled', 'expired' => PaymentStatus::Canceled,
            'failed' => PaymentStatus::Failed,
            'charged_back' => PaymentStatus::Disputed,
            default => PaymentStatus::Unknown,
        };
    }

    /** @param  array<string, mixed>  $order */
    private function toPayment(array $order): Payment
    {
        if (! isset($order['id'])) {
            throw new GatewayException('Mercado Pago returned an order without id.');
        }

        $currency = (string) ($order['currency_id'] ?? $this->config['currency'] ?? 'ARS');
        $status = self::mapStatus($order['status'] ?? null, $order['status_detail'] ?? null);
        $payment = (array) ($order['transactions']['payments'][0] ?? []);
        unset($order['transactions']['payments'][0]['payment_method']['token']);

        return new Payment(
            gateway: $this->name(),
            id: (string) $order['id'],
            status: $status,
            amount: Money::fromDecimal((string) ($order['total_amount'] ?? '0'), $currency),
            reference: isset($order['external_reference']) ? (string) $order['external_reference'] : null,
            providerStatus: isset($order['status']) ? (string) $order['status'] : null,
            providerStatusDetail: isset($order['status_detail']) ? (string) $order['status_detail'] : null,
            transactionId: isset($payment['id']) ? (string) $payment['id'] : null,
            nextAction: $status === PaymentStatus::RequiresAction ? $this->nextAction((string) $order['id'], $payment) : null,
            raw: $order,
        );
    }

    /** @param  array<string, mixed>  $payment */
    private function nextAction(string $orderId, array $payment): ?NextAction
    {
        $checkout = rtrim((string) ($this->config['checkout_url'] ?? ''), '/');
        if ($checkout !== '') {
            return NextAction::redirect("{$checkout}/plaza/checkout/{$orderId}", false);
        }

        // REQUIRES VERIFICATION against current Orders docs before relying on it in production.
        $url = $payment['payment_method']['ticket_url'] ?? null;

        return is_string($url) && $url !== '' ? NextAction::redirect($url, false) : null;
    }

    /** @return array<string, string> */
    private function readHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->config['access_token']];
    }

    /** @return array<string, string> */
    private function writeHeaders(string $idempotencyKey, bool $create = false): array
    {
        $headers = $this->readHeaders() + ['X-Idempotency-Key' => $idempotencyKey];
        if ($create && ! empty($this->config['scenario'])) {
            $headers['X-Emulator-Scenario'] = (string) $this->config['scenario'];
        }

        return $headers;
    }
}
