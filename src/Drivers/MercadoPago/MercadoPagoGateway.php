<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Drivers\MercadoPago;

use DateTimeImmutable;
use GuzzleHttp\ClientInterface;
use Ronu\PaymentGateways\Capability;
use Ronu\PaymentGateways\CaptureMode;
use Ronu\PaymentGateways\Checkout;
use Ronu\PaymentGateways\CheckoutStatus;
use Ronu\PaymentGateways\Config\Environment;
use Ronu\PaymentGateways\Config\GatewayConfig;
use Ronu\PaymentGateways\Contracts\Gateway;
use Ronu\PaymentGateways\Contracts\HostedCheckout;
use Ronu\PaymentGateways\CreatePayment;
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
final class MercadoPagoGateway implements Gateway, HostedCheckout
{
    public const HOSTS = ['sandbox' => 'https://api.mercadopago.com', 'live' => 'https://api.mercadopago.com'];

    public const EMULATOR_PROFILE = 'plaza';

    private Transport $http;

    public function __construct(private readonly GatewayConfig $config, ?ClientInterface $client = null)
    {
        $config->credential('access_token');

        $this->http = new Transport(
            $config->resolveBaseUrl(self::HOSTS, self::EMULATOR_PROFILE),
            $client,
            $config->timeout(),
            $config->connectTimeout(),
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

            return new Refund(
                id: (string) ($refund['id'] ?? $idempotencyKey),
                amount: isset($refund['amount']) ? self::money($refund['amount'], $currency) : ($amount ?? $payment->amount ?? Money::of(0, $currency)),
                status: (string) ($refund['status'] ?? 'approved'),
                paymentId: $paymentId,
                payment: $payment,
                raw: $refund,
            );
        }

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
        $payment = $this->orderToPayment($order);
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
            nextAction: $status === PaymentStatus::RequiresAction ? $this->orderNextAction((string) $order['id'], $payment) : null,
            raw: $order,
        );
    }

    /** @param  array<string, mixed>  $payment */
    private function orderNextAction(string $orderId, array $payment): ?NextAction
    {
        if ($this->config->isEmulator()) {
            $checkout = $this->config->emulatorCheckoutUrl() ?? $this->config->emulatorApiUrl();

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
