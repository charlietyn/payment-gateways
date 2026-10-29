<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Drivers\MercadoPago;

use DateTimeImmutable;
use Ronu\PaymentGateways\Capability;
use Ronu\PaymentGateways\CaptureMode;
use Ronu\PaymentGateways\Checkout;
use Ronu\PaymentGateways\CheckoutStatus;
use Ronu\PaymentGateways\Config\Environment;
use Ronu\PaymentGateways\Config\GatewayConfig;
use Ronu\PaymentGateways\Contracts\Gateway;
use Ronu\PaymentGateways\Contracts\HostedCheckout;
use Ronu\PaymentGateways\Contracts\RefundLookup;
use Ronu\PaymentGateways\Contracts\DisputeLookup;
use Ronu\PaymentGateways\Dispute;
use Ronu\PaymentGateways\CreatePayment;
use Ronu\PaymentGateways\EventType;
use Ronu\PaymentGateways\Exceptions\GatewayException;
use Ronu\PaymentGateways\Exceptions\InvalidWebhookSignature;
use Ronu\PaymentGateways\Exceptions\UnsupportedOperation;
use Ronu\PaymentGateways\Http\Transport;
use Ronu\PaymentGateways\Money;
use Ronu\PaymentGateways\NextAction;
use Ronu\PaymentGateways\Payment;
use Ronu\PaymentGateways\PaymentMethod;
use Ronu\PaymentGateways\PaymentStatus;
use Ronu\PaymentGateways\Refund;
use Ronu\PaymentGateways\Services;
use Ronu\PaymentGateways\Support\Headers;
use Ronu\PaymentGateways\WebhookEvent;

/**
 * Mercado Pago, two APIs behind one driver:
 *
 *  - createPayment(): Orders API (`/v1/orders`). A card needs the Card Payment Brick token
 *    (PaymentMethod::card); without a method it creates a cash ticket. Ids start with `ORD`.
 *  - createCheckout(): Checkout Pro (`/checkout/preferences` → `init_point`). The buyer pays on
 *    Mercado Pago; the result is a Payments API payment (`/v1/payments/{id}`), numeric id.
 *
 * getPayment(), capture(), cancel() and refund() take either id and route by its shape.
 * On the emulator (Plaza) there are no preferences: createCheckout() creates an order and
 * returns Plaza's buyer page, so the same consumer code runs end to end.
 *
 * Config:
 *  - credentials.access_token (TEST-… / APP_USR-…)
 *  - webhooks.secret, webhooks.tolerance_seconds (600, the window Mercado Pago documents)
 *  - options.currency: the site currency (ARS for MLA) — Orders has no currency field
 *  - options.ticket_method: the ticket created when no PaymentMethod is given (pagofacil)
 *  - options.notification_url, options.statement_descriptor: Checkout Pro
 *  - emulator.checkout_url: where Plaza serves {checkout_url}/plaza/checkout/{id}
 */
final class MercadoPagoGateway implements Gateway, HostedCheckout, RefundLookup, DisputeLookup
{
    public const HOSTS = ['sandbox' => 'https://api.mercadopago.com', 'live' => 'https://api.mercadopago.com'];

    public const EMULATOR_PROFILE = 'plaza';

    private Transport $http;

    public function __construct(private readonly GatewayConfig $config, Services $services = new Services())
    {
        $config->credential('access_token');

        $this->http = Transport::for(
            $config,
            $config->resolveBaseUrl(self::HOSTS, self::EMULATOR_PROFILE),
            $services,
            fn (array $body): ?string => isset($body['error']) ? (string) $body['error']
                : (isset($body['errors'][0]['code']) ? (string) $body['errors'][0]['code'] : null),
        );
    }

    public function name(): string
    {
        return 'mercadopago';
    }

    public function supports(Capability $capability): bool
    {
        return $capability !== Capability::PartialCapture;
    }

    public function createPayment(CreatePayment $request): Payment
    {
        $amount = $request->amount->toDecimal();

        $body = array_filter([
            'type' => 'online',
            'processing_mode' => 'automatic',
            'capture_mode' => $request->captureMode === CaptureMode::Manual ? 'manual' : 'automatic',
            'total_amount' => $amount,
            'external_reference' => $request->reference,
            'description' => $request->description,
            'payer' => $this->payer($request, 'first_name', 'last_name'),
            'transactions' => ['payments' => [[
                'amount' => $amount,
                'payment_method' => $this->orderPaymentMethod($request->paymentMethod),
            ]]],
        ], fn ($value) => $value !== null && $value !== []);

        $order = $this->http->request('POST', '/v1/orders', $this->writeHeaders($request->idempotencyKey) + $this->config->emulatorHeaders(), $body);

        return $this->orderToPayment($order ?? []);
    }

    /**
     * Checkout Pro: a preference whose `init_point` the buyer opens. Automatic capture only.
     */
    public function createCheckout(CreatePayment $request): Checkout
    {
        if ($this->config->isEmulator()) {
            return Checkout::forPayment($this->createPayment($request));
        }
        if ($request->captureMode === CaptureMode::Manual) {
            throw new UnsupportedOperation('Mercado Pago Checkout Pro captures at once; use createPayment() for CaptureMode::Manual.');
        }

        $backUrls = $request->returnUrl !== null
            ? ['success' => $request->returnUrl, 'pending' => $request->returnUrl, 'failure' => $request->cancelUrl ?? $request->returnUrl]
            : null;

        $body = array_filter([
            'items' => [[
                'id' => $request->reference,
                'title' => $request->description ?? $request->reference,
                'quantity' => 1,
                'currency_id' => $request->amount->currency,
                'unit_price' => (float) $request->amount->toDecimal(),
            ]],
            'payer' => $this->payer($request, 'name', 'surname'),
            'external_reference' => $request->reference,
            'back_urls' => $backUrls,
            'auto_return' => $backUrls !== null ? 'approved' : null,
            'notification_url' => $this->config->option('notification_url'),
            'statement_descriptor' => $this->config->option('statement_descriptor'),
            'metadata' => $request->metadata !== [] ? $request->metadata : null,
            'expires' => $request->expiresAt !== null ? true : null,
            'expiration_date_to' => $request->expiresAt?->format(DATE_RFC3339_EXTENDED),
        ], fn ($value) => $value !== null && $value !== []);

        $preference = $this->http->request('POST', '/checkout/preferences', $this->writeHeaders($request->idempotencyKey), $body) ?? [];

        return $this->preferenceToCheckout($preference, null);
    }

    /** The preference, and the newest payment carrying its external_reference. */
    public function getCheckout(string $checkoutId): ?Checkout
    {
        if ($this->config->isEmulator()) {
            $payment = $this->getPayment($checkoutId);

            return $payment === null ? null : Checkout::forPayment($payment);
        }
        if (! preg_match('/^[A-Za-z0-9_-]{1,128}$/', $checkoutId)) {
            return null;
        }

        $preference = $this->http->request('GET', '/checkout/preferences/' . $checkoutId, $this->readHeaders(), allowNotFound: true);
        if ($preference === null) {
            return null;
        }

        $payment = null;
        if (isset($preference['external_reference']) && $preference['external_reference'] !== '') {
            $search = $this->http->request('GET', '/v1/payments/search?' . http_build_query([
                'external_reference' => (string) $preference['external_reference'],
                'sort' => 'date_created',
                'criteria' => 'desc',
            ]), $this->readHeaders()) ?? [];
            $first = $search['results'][0] ?? null;
            $payment = is_array($first) ? $first : null;
        }

        return $this->preferenceToCheckout($preference, $payment);
    }

    public function getPayment(string $paymentId): ?Payment
    {
        if (self::isPaymentsApiId($paymentId)) {
            $payment = $this->http->request('GET', '/v1/payments/' . $paymentId, $this->readHeaders(), allowNotFound: true);

            return $payment === null ? null : $this->paymentToPayment($payment);
        }
        if (! preg_match('/^[A-Za-z0-9_-]{1,128}$/', $paymentId)) {
            return null;
        }

        $order = $this->http->request('GET', '/v1/orders/' . rawurlencode($paymentId), $this->readHeaders(), allowNotFound: true);

        return $order === null ? null : $this->orderToPayment($order);
    }

    /** Orders embeds individual refunds in GET /v1/orders/{id}; numeric Payments IDs are a separate API. */
    public function getRefund(string $paymentId, string $refundId): ?Refund
    {
        if (self::isPaymentsApiId($paymentId)) {
            throw new UnsupportedOperation('Refund lookup currently supports Mercado Pago Orders only.');
        }
        if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $paymentId) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $refundId)) {
            throw new \InvalidArgumentException('Invalid order or refund identity.');
        }
        $order = $this->http->request('GET', '/v1/orders/' . rawurlencode($paymentId), $this->readHeaders(), allowNotFound: true);
        if ($order === null) {
            return null;
        }
        if (($order['id'] ?? null) !== $paymentId) {
            throw new GatewayException('Refund lookup returned a different order.');
        }
        $refunds = $order['transactions']['refunds'] ?? [];
        if (!is_array($refunds) || !array_is_list($refunds)) {
            throw new GatewayException('Invalid refund history.');
        }
        $matches = array_values(array_filter($refunds, static fn ($row): bool => is_array($row) && ($row['id'] ?? null) === $refundId));
        if ($matches === []) {
            return null;
        }
        $row = $matches[0];
        $payments = $order['transactions']['payments'] ?? [];
        $currency = (string) $this->config->option('currency', 'ARS');
        if (count($matches) !== 1 || !is_array($payments) || !is_string($row['transaction_id'] ?? null) ||
            !in_array($row['transaction_id'], array_column($payments, 'id'), true) ||
            !is_string($row['amount'] ?? null) || !is_string($row['status'] ?? null) || $row['status'] === '' ||
            (isset($order['currency_id']) && $order['currency_id'] !== $currency)) {
            throw new GatewayException('Refund lookup lacks unambiguous individual evidence.');
        }
        $amount = Money::fromDecimal($row['amount'], $currency);
        if ($amount->minor < 1) {
            throw new GatewayException('Invalid individual refund amount.');
        }
        return new Refund(id: $refundId, amount: $amount, status: $row['status'], paymentId: $paymentId, raw: $row);
    }

    /** GET case + GET order, with explicit seller and individual transaction correlation. */
    public function getDispute(string $paymentId, string $disputeId, string $merchantId): ?Dispute
    {
        if (self::isPaymentsApiId($paymentId)) {
            throw new UnsupportedOperation('Dispute lookup supports Mercado Pago Orders only.');
        }
        if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $paymentId)
            || !preg_match('/^[0-9]{1,128}$/D', $disputeId) || !preg_match('/^[0-9]{1,128}$/D', $merchantId)) {
            throw new \InvalidArgumentException('Invalid order, chargeback or seller identity.');
        }
        $case = $this->http->request('GET', '/v1/chargebacks/'.$disputeId, $this->readHeaders() + ['X-Caller-Id' => $merchantId], allowNotFound: true);
        if ($case === null) { return null; }
        $payments = $case['payments'] ?? null;
        $currency = (string) $this->config->option('currency', 'ARS');
        if ((!is_string($case['id'] ?? null) && !is_int($case['id'] ?? null)) || (string) $case['id'] !== $disputeId
            || !is_array($payments) || !array_is_list($payments) || count($payments) !== 1
            || (!is_string($payments[0]) && !is_int($payments[0])) || (string) $payments[0] === ''
            || ($case['currency'] ?? null) !== $currency || !array_key_exists('coverage_applied', $case)
            || ($case['coverage_applied'] !== null && !is_bool($case['coverage_applied']))) {
            throw new GatewayException('Chargeback lacks unambiguous individual evidence.');
        }
        $value = $case['amount'] ?? null;
        if (is_float($value)) {
            $decimal = number_format($value, Money::exponentOf($currency), '.', '');
            // Never round extra decimals or accept a float outside exact minor-unit range.
            if (!is_finite($value) || (float) $decimal !== $value || abs($value) * (10 ** Money::exponentOf($currency)) > 9007199254740991) {
                throw new GatewayException('Chargeback amount requires exact monetary evidence.');
            }
            $value = $decimal;
        }
        if (!is_string($value) && !is_int($value)) { throw new GatewayException('Chargeback amount is missing.'); }
        $amount = Money::fromDecimal((string) $value, $currency);
        if ($amount->minor < 1) { throw new GatewayException('Chargeback principal must be positive.'); }
        $order = $this->http->request('GET', '/v1/orders/'.rawurlencode($paymentId), $this->readHeaders(), allowNotFound: true);
        if ($order === null) { return null; }
        $transactions = $order['transactions']['payments'] ?? null;
        if (($order['id'] ?? null) !== $paymentId || !is_array($transactions) || !array_is_list($transactions)) {
            throw new GatewayException('Chargeback returned an unrelated order.');
        }
        $matches = array_values(array_filter($transactions, static fn ($row): bool => is_array($row)
            && (is_string($row['id'] ?? null) || is_int($row['id'] ?? null)) && (string) $row['id'] === (string) $payments[0]));
        if (count($matches) !== 1 || (isset($order['currency_id']) && $order['currency_id'] !== $currency)) {
            throw new GatewayException('Chargeback does not identify one transaction in this order.');
        }
        return new Dispute($disputeId, $paymentId, $amount, match ($case['coverage_applied']) { null => 'opened', true => 'won', false => 'lost' });
    }

    public function capture(string $paymentId, ?Money $amount = null, ?string $idempotencyKey = null): Payment
    {
        $key = $idempotencyKey ?? "capture-{$paymentId}";

        if (self::isPaymentsApiId($paymentId)) {
            $body = ['capture' => true] + ($amount !== null ? ['transaction_amount' => (float) $amount->toDecimal()] : []);

            return $this->paymentToPayment($this->http->request('PUT', '/v1/payments/' . $paymentId, $this->writeHeaders($key), $body) ?? []);
        }

        // Orders capture is total and takes an empty body.
        if ($amount !== null) {
            $current = $this->getPayment($paymentId);
            if ($current && ! $amount->equals($current->amount)) {
                throw new UnsupportedOperation('Mercado Pago Orders only captures the full amount.');
            }
        }

        $order = $this->http->request('POST', '/v1/orders/' . rawurlencode($paymentId) . '/capture', $this->writeHeaders($key), (object) []);

        return $this->orderToPayment($order ?? []);
    }

    public function cancel(string $paymentId, ?string $idempotencyKey = null): Payment
    {
        $key = $idempotencyKey ?? "cancel-{$paymentId}";

        if (self::isPaymentsApiId($paymentId)) {
            return $this->paymentToPayment($this->http->request('PUT', '/v1/payments/' . $paymentId, $this->writeHeaders($key), ['status' => 'cancelled']) ?? []);
        }

        $order = $this->http->request('POST', '/v1/orders/' . rawurlencode($paymentId) . '/cancel', $this->writeHeaders($key), (object) []);

        return $this->orderToPayment($order ?? []);
    }

    public function refund(string $paymentId, ?Money $amount, string $idempotencyKey): Refund
    {
        if (self::isPaymentsApiId($paymentId)) {
            $body = $amount !== null ? ['amount' => (float) $amount->toDecimal()] : (object) [];
            $refund = $this->http->request('POST', "/v1/payments/{$paymentId}/refunds", $this->writeHeaders($idempotencyKey), $body) ?? [];
            $payment = $this->getPayment($paymentId);
            $currency = $payment !== null ? $payment->amount->currency : (string) $this->config->option('currency', 'ARS');
            if (empty($refund['id']) || !isset($refund['amount'], $refund['status']) ||
                (isset($refund['payment_id']) && (string) $refund['payment_id'] !== $paymentId)) {
                throw new GatewayException('Refund response lacks individual evidence; reconcile before retrying.', false);
            }

            return new Refund(
                id: (string) $refund['id'],
                amount: self::money($refund['amount'], $currency),
                status: (string) $refund['status'],
                paymentId: $paymentId,
                payment: $payment,
                raw: $refund,
            );
        }

        $body = (object) [];
        $current = $this->getPayment($paymentId) ?? throw new GatewayException("Unknown order {$paymentId}.", false, 404);
        $knownRefunds = array_column((array) ($current->raw['transactions']['refunds'] ?? []), 'id');
        if ($amount !== null) {
            // A partial refund names the transaction; `{}` refunds what remains.
            if ($current->transactionId === null) {
                throw new GatewayException('The order has no payment transaction to refund.');
            }
            $body = ['transactions' => [['id' => $current->transactionId, 'amount' => $amount->toDecimal()]]];
        }

        $order = $this->http->request('POST', '/v1/orders/' . rawurlencode($paymentId) . '/refund', $this->writeHeaders($idempotencyKey), $body) ?? [];
        $payment = $this->orderToPayment($order);
        $refunds = (array) ($order['transactions']['refunds'] ?? []);
        $newRefunds = array_values(array_filter($refunds, static fn ($row): bool =>
            is_array($row) && isset($row['id']) && !in_array($row['id'], $knownRefunds, true)));
        if (count($newRefunds) !== 1 || $payment->id !== $paymentId) {
            throw new GatewayException('Refund response is ambiguous; reconcile before retrying.', false);
        }
        $last = $newRefunds[0];
        if (empty($last['id']) || !isset($last['amount'], $last['status'])) {
            throw new GatewayException('Refund response lacks individual evidence; reconcile before retrying.', false);
        }

        return new Refund(
            id: (string) $last['id'],
            amount: Money::fromDecimal((string) $last['amount'], $payment->amount->currency),
            status: (string) $last['status'],
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
        $secret = (string) $this->config->webhook('secret', '');
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

        if (abs(time() - $ts) > (int) $this->config->webhook('tolerance_seconds', 600)) {
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
            // `order`: Orders API; `payment`: a Checkout Pro payment.
            paymentId: in_array($type, ['order', 'payment'], true) ? $dataId : null,
            payload: $payload,
            kind: self::eventType($type, isset($payload['action']) ? (string) $payload['action'] : null),
        );
    }

    public function paymentIdForEvent(WebhookEvent $event): ?string
    {
        return $event->paymentId;
    }

    /**
     * Notification → canonical event. Payments notifications carry no state
     * (`payment.created` / `payment.updated`): PaymentUpdated, re-read the payment.
     */
    public static function eventType(string $type, ?string $action): EventType
    {
        return match ($type) {
            'order' => match ($action) {
                'order.processed' => EventType::PaymentCaptured,
                'order.refunded' => EventType::PaymentRefunded,
                'order.canceled', 'order.expired' => EventType::PaymentCanceled,
                'order.failed' => EventType::PaymentFailed,
                'order.charged_back' => EventType::DisputeOpened,
                default => EventType::PaymentUpdated,
            },
            'payment' => EventType::PaymentUpdated,
            'chargebacks' => EventType::DisputeOpened,
            default => EventType::Unknown,
        };
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

    /** Payments API (Checkout Pro) status → canonical. */
    public static function mapPaymentStatus(?string $status, bool $partiallyRefunded = false): PaymentStatus
    {
        return match ($status) {
            'pending' => PaymentStatus::RequiresAction,
            'in_process' => PaymentStatus::Processing,
            'authorized' => PaymentStatus::Authorized,
            'approved' => $partiallyRefunded ? PaymentStatus::PartiallyRefunded : PaymentStatus::Captured,
            'refunded' => PaymentStatus::Refunded,
            'cancelled' => PaymentStatus::Canceled,
            'rejected' => PaymentStatus::Failed,
            'charged_back', 'in_mediation' => PaymentStatus::Disputed,
            default => PaymentStatus::Unknown,
        };
    }

    /** Payments API ids are numeric; Orders ids are not (`ORD…`). */
    private static function isPaymentsApiId(string $id): bool
    {
        return $id !== '' && strlen($id) <= 20 && ctype_digit($id);
    }

    /** @return array<string, string|int> */
    private function orderPaymentMethod(?PaymentMethod $method): array
    {
        $method ??= PaymentMethod::ticket((string) $this->config->option('ticket_method', 'pagofacil'));

        return match ($method->type) {
            PaymentMethod::CARD => [
                'id' => $method->id ?? throw new \InvalidArgumentException('Mercado Pago needs the card brand: PaymentMethod::card($token, $paymentMethodIdFromTheBrick).'),
                'type' => $method->debit ? 'debit_card' : 'credit_card',
                'token' => (string) $method->token,
                'installments' => $method->installments,
            ],
            default => array_filter(['id' => $method->id ?? $method->type, 'type' => $method->type, 'token' => $method->token]),
        };
    }

    /** @return array<string, mixed>|null */
    private function payer(CreatePayment $request, string $firstName, string $lastName): ?array
    {
        $payer = $request->payer;
        if ($payer === null) {
            return null;
        }

        return array_filter([
            'email' => $payer->email,
            $firstName => $payer->firstName,
            $lastName => $payer->lastName,
            'identification' => $payer->documentNumber
                ? ['type' => $payer->documentType ?? 'DNI', 'number' => $payer->documentNumber]
                : null,
        ]) ?: null;
    }

    /** @param  array<string, mixed>  $order */
    private function orderToPayment(array $order): Payment
    {
        if (! isset($order['id'])) {
            throw new GatewayException('Mercado Pago returned an order without id.');
        }

        $currency = (string) ($order['currency_id'] ?? $this->config->option('currency', 'ARS'));
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
            // Missing evidence is unknown, never the requested amount or a fabricated zero.
            capturedAmount: isset($order['total_paid_amount']) ? Money::fromDecimal((string) $order['total_paid_amount'], $currency) : null,
            nextAction: $status === PaymentStatus::RequiresAction ? $this->orderNextAction((string) $order['id'], $payment) : null,
            raw: $order,
        );
    }

    /** @param  array<string, mixed>  $payment */
    private function orderNextAction(string $orderId, array $payment): ?NextAction
    {
        if ($this->config->isEmulator()) {
            $checkout = $this->config->emulatorCheckoutUrl(self::EMULATOR_PROFILE) ?? $this->config->emulatorRoot(self::EMULATOR_PROFILE);

            return NextAction::redirect("{$checkout}/plaza/checkout/{$orderId}", false);
        }

        // Orders OpenAPI (contracts/plaza/orders.json): transactions.payments[].payment_method.ticket_url.
        $url = $payment['payment_method']['ticket_url'] ?? null;

        return is_string($url) && $url !== '' ? NextAction::redirect($url, false) : null;
    }

    /** @param  array<string, mixed>  $payment  A Payments API resource. */
    private function paymentToPayment(array $payment): Payment
    {
        if (! isset($payment['id'])) {
            throw new GatewayException('Mercado Pago returned a payment without id.');
        }

        $currency = (string) ($payment['currency_id'] ?? $this->config->option('currency', 'ARS'));
        $amount = self::money($payment['transaction_amount'] ?? 0, $currency);
        $refunded = self::money($payment['transaction_amount_refunded'] ?? 0, $currency);
        $status = self::mapPaymentStatus($payment['status'] ?? null, ! $refunded->isZero() && $refunded->minor < $amount->minor);

        $ticket = $payment['transaction_details']['external_resource_url'] ?? $payment['point_of_interaction']['transaction_data']['ticket_url'] ?? null;

        return new Payment(
            gateway: $this->name(),
            id: (string) $payment['id'],
            status: $status,
            amount: $amount,
            reference: isset($payment['external_reference']) && $payment['external_reference'] !== '' ? (string) $payment['external_reference'] : null,
            providerStatus: isset($payment['status']) ? (string) $payment['status'] : null,
            providerStatusDetail: isset($payment['status_detail']) ? (string) $payment['status_detail'] : null,
            transactionId: (string) $payment['id'],
            capturedAmount: $status->isPaid() ? $amount : null,
            refundedAmount: $refunded->isZero() ? null : $refunded,
            nextAction: $status === PaymentStatus::RequiresAction && is_string($ticket) && $ticket !== '' ? NextAction::redirect($ticket, false) : null,
            raw: $payment,
        );
    }

    /**
     * @param  array<string, mixed>  $preference
     * @param  array<string, mixed>|null  $payment  The newest payment for its external_reference.
     */
    private function preferenceToCheckout(array $preference, ?array $payment): Checkout
    {
        if (! isset($preference['id'])) {
            throw new GatewayException('Mercado Pago returned a preference without id.');
        }

        $expiresAt = isset($preference['expiration_date_to']) && is_string($preference['expiration_date_to'])
            ? (new DateTimeImmutable($preference['expiration_date_to']))
            : null;
        $expired = ! empty($preference['expires']) && $expiresAt !== null && $expiresAt < new DateTimeImmutable();
        // A rejected attempt leaves the page open: the buyer may try another card.
        $paid = $payment !== null && ! in_array($payment['status'] ?? null, ['rejected', 'cancelled'], true);

        $status = match (true) {
            $paid => CheckoutStatus::Completed,
            $expired => CheckoutStatus::Expired,
            default => CheckoutStatus::Open,
        };

        // Test credentials pay on sandbox_init_point when Mercado Pago returns one.
        $url = $this->config->environment === Environment::Sandbox
            ? ($preference['sandbox_init_point'] ?? $preference['init_point'] ?? null)
            : ($preference['init_point'] ?? null);

        return new Checkout(
            gateway: $this->name(),
            id: (string) $preference['id'],
            status: $status,
            url: $status === CheckoutStatus::Open && is_string($url) ? $url : null,
            returnsToMerchant: ($preference['auto_return'] ?? null) === 'approved' && ! empty($preference['back_urls']['success']),
            paymentId: $payment !== null && isset($payment['id']) ? (string) $payment['id'] : null,
            reference: isset($preference['external_reference']) ? (string) $preference['external_reference'] : null,
            expiresAt: $expiresAt,
            raw: $preference,
        );
    }

    /** Mercado Pago sends Payments API amounts as JSON numbers, Orders amounts as strings. */
    private static function money(mixed $value, string $currency): Money
    {
        if (is_int($value) || is_float($value)) {
            $value = number_format((float) $value, Money::exponentOf($currency), '.', '');
        }

        return Money::fromDecimal(is_string($value) ? $value : '0', $currency);
    }

    /** @return array<string, string> */
    private function readHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->config->credential('access_token')];
    }

    /** @return array<string, string> */
    private function writeHeaders(string $idempotencyKey): array
    {
        return $this->readHeaders() + ['X-Idempotency-Key' => $idempotencyKey];
    }
}
