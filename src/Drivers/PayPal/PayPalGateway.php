<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Drivers\PayPal;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;
use InvalidArgumentException;
use Ronu\PaymentGateways\Capability;
use Ronu\PaymentGateways\CaptureMode;
use Ronu\PaymentGateways\Checkout;
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
use Ronu\PaymentGateways\PaymentStatus;
use Ronu\PaymentGateways\Refund;
use Ronu\PaymentGateways\Support\Headers;
use Ronu\PaymentGateways\WebhookEvent;
use Throwable;

/**
 * PayPal Orders v2 + Payments v2 with OAuth client credentials.
 *
 * PayPal splits consent from collection: the buyer approves (status Approved),
 * then the merchant calls capture(). With CaptureMode::Manual, capture() first
 * authorizes the approved order, then captures the authorization.
 *
 *  - createCheckout(): an order with return/cancel URLs; the buyer approves on PayPal and
 *    comes back. The checkout id IS the order id.
 *  - createPayment() without returnUrl: an order for the PayPal JS SDK buttons on your page
 *    (they approve it with the order id). Card tokens are not taken here.
 *
 * Config:
 *  - credentials.client_id + credentials.client_secret (or a ready credentials.access_token)
 *  - webhooks.id: the id PayPal assigned to your webhook (Harbor: the app's webhookSecret)
 *  - webhooks.cert_hosts: hosts a signing certificate may be downloaded from
 *  - webhooks.certificate: a pinned PEM, instead of downloading one
 *  - webhooks.tolerance_seconds (300)
 *  - options.brand_name, options.locale, options.shipping_preference (NO_SHIPPING for digital goods)
 */
final class PayPalGateway implements Gateway, HostedCheckout
{
    public const HOSTS = ['sandbox' => 'https://api-m.sandbox.paypal.com', 'live' => 'https://api-m.paypal.com'];

    public const EMULATOR_PROFILE = 'harbor';

    public const DEFAULT_CERT_HOSTS = ['api.paypal.com', 'api-m.paypal.com', 'api.sandbox.paypal.com', 'api-m.sandbox.paypal.com'];

    private Transport $http;

    private ClientInterface $client;

    private ?string $token = null;

    private int $tokenExpiresAt = 0;

    /** @var array<string, string> */
    private array $certificates = [];

    public function __construct(private readonly GatewayConfig $config, ?ClientInterface $client = null)
    {
        if ($config->credentialOrNull('access_token') === null) {
            $config->credential('client_id');
            $config->credential('client_secret');
        }

        $this->client = $client ?? new Client();
        $this->http = new Transport(
            $config->resolveBaseUrl(self::HOSTS, self::EMULATOR_PROFILE),
            $this->client,
            $config->timeout(),
            $config->connectTimeout(),
            fn (array $body): ?string => isset($body['details'][0]['issue']) ? (string) $body['details'][0]['issue']
                : (isset($body['name']) ? (string) $body['name'] : (isset($body['error']) ? (string) $body['error'] : null)),
        );
    }

    public function name(): string
    {
        return 'paypal';
    }

    public function supports(Capability $capability): bool
    {
        return $capability !== Capability::OfflinePayments;
    }

    public function createPayment(CreatePayment $request): Payment
    {
        if ($request->paymentMethod !== null) {
            throw new UnsupportedOperation('PayPal: approve the order with the JS SDK or createCheckout(); payment method tokens are not taken.');
        }

        $unit = array_filter([
            'reference_id' => 'default',
            'custom_id' => $request->reference,
            'description' => $request->description,
            'amount' => ['currency_code' => $request->amount->currency, 'value' => $request->amount->toDecimal()],
        ], fn ($value) => $value !== null);

        $body = [
            'intent' => $request->captureMode === CaptureMode::Manual ? 'AUTHORIZE' : 'CAPTURE',
            'purchase_units' => [$unit],
        ];

        $context = array_filter(['return_url' => $request->returnUrl, 'cancel_url' => $request->cancelUrl]);
        if ($context !== []) {
            $body['payment_source'] = ['paypal' => ['experience_context' => $context + array_filter([
                'user_action' => 'PAY_NOW',
                'brand_name' => $this->config->option('brand_name'),
                'locale' => $this->config->option('locale'),
                'shipping_preference' => $this->config->option('shipping_preference'),
            ])]];
        }

        $order = $this->http->request('POST', '/v2/checkout/orders', $this->headers($request->idempotencyKey), $body) ?? [];

        return $this->toPayment($order, $request->returnUrl !== null);
    }

    /** The order with return URLs: PayPal's approval page is the hosted checkout. */
    public function createCheckout(CreatePayment $request): Checkout
    {
        if ($request->returnUrl === null) {
            throw new InvalidArgumentException('PayPal checkout needs returnUrl: the buyer comes back there to be captured.');
        }

        return Checkout::forPayment($this->createPayment($request));
    }

    public function getCheckout(string $checkoutId): ?Checkout
    {
        $payment = $this->getPayment($checkoutId);

        return $payment === null ? null : Checkout::forPayment($payment);
    }

    public function getPayment(string $paymentId): ?Payment
    {
        if (! preg_match('/^[A-Za-z0-9-]{1,64}$/', $paymentId)) {
            return null;
        }

        $order = $this->http->request('GET', '/v2/checkout/orders/' . $paymentId, $this->headers(), allowNotFound: true);

        return $order === null ? null : $this->toPayment($order, false);
    }

    public function capture(string $paymentId, ?Money $amount = null, ?string $idempotencyKey = null): Payment
    {
        $key = $idempotencyKey ?? "capture-{$paymentId}";
        $order = $this->getPayment($paymentId) ?? throw new GatewayException("Unknown order {$paymentId}.", false, 404);

        if ($order->status === PaymentStatus::Approved) {
            $intent = (string) ($order->raw['intent'] ?? 'CAPTURE');
            if ($intent === 'CAPTURE') {
                if ($amount !== null && ! $amount->equals($order->amount)) {
                    throw new UnsupportedOperation('PayPal captures an order in full; partial capture needs CaptureMode::Manual.');
                }
                $this->http->request('POST', "/v2/checkout/orders/{$paymentId}/capture", $this->headers($key), (object) []);

                return $this->reload($paymentId, 'capture');
            }

            $this->http->request('POST', "/v2/checkout/orders/{$paymentId}/authorize", $this->headers("{$key}-authorize"), (object) []);
            $order = $this->reload($paymentId, 'authorize');
        }

        $authorization = $order->raw['purchase_units'][0]['payments']['authorizations'][0]['id'] ?? null;
        if ($order->status !== PaymentStatus::Authorized || ! is_string($authorization)) {
            throw new UnsupportedOperation("Order {$paymentId} is {$order->status->value}; only approved or authorized orders can be captured.");
        }

        $body = ['final_capture' => true] + ($amount !== null ? ['amount' => ['currency_code' => $amount->currency, 'value' => $amount->toDecimal()]] : []);
        $this->http->request('POST', "/v2/payments/authorizations/{$authorization}/capture", $this->headers($key), $body);

        return $this->reload($paymentId, 'capture');
    }

    public function cancel(string $paymentId, ?string $idempotencyKey = null): Payment
    {
        $order = $this->getPayment($paymentId) ?? throw new GatewayException("Unknown order {$paymentId}.", false, 404);
        $authorization = $order->raw['purchase_units'][0]['payments']['authorizations'][0]['id'] ?? null;

        if ($order->status !== PaymentStatus::Authorized || ! is_string($authorization)) {
            // An unapproved PayPal order simply expires; there is nothing to call.
            throw new UnsupportedOperation("Order {$paymentId} is {$order->status->value}; PayPal only voids authorizations.");
        }

        $this->http->request('POST', "/v2/payments/authorizations/{$authorization}/void", $this->headers($idempotencyKey ?? "void-{$paymentId}"), (object) []);

        return $this->reload($paymentId, 'void');
    }

    public function refund(string $paymentId, ?Money $amount, string $idempotencyKey): Refund
    {
        $order = $this->getPayment($paymentId) ?? throw new GatewayException("Unknown order {$paymentId}.", false, 404);
        if ($order->transactionId === null) {
            throw new UnsupportedOperation("Order {$paymentId} has no capture to refund.");
        }

        $body = $amount !== null ? ['amount' => ['currency_code' => $amount->currency, 'value' => $amount->toDecimal()]] : (object) [];
        $refund = $this->http->request('POST', "/v2/payments/captures/{$order->transactionId}/refund", $this->headers($idempotencyKey), $body) ?? [];

        if (! isset($refund['id'])) {
            throw new GatewayException('PayPal returned a refund without id.');
        }

        $value = $refund['amount']['value'] ?? null;

        return new Refund(
            id: (string) $refund['id'],
            amount: is_string($value) ? Money::fromDecimal($value, (string) ($refund['amount']['currency_code'] ?? $order->amount->currency)) : ($amount ?? $order->amount),
            status: (string) ($refund['status'] ?? 'PENDING'),
            paymentId: $paymentId,
            payment: $this->getPayment($paymentId),
            raw: $refund,
        );
    }

    /**
     * PayPal signs `<transmission-id>|<transmission-time>|<webhook-id>|<crc32(body)>` with
     * RSA-SHA256. The certificate comes from `paypal-cert-url`, which is attacker-supplied:
     * it is only fetched from webhook_cert_hosts, or replaced by a pinned PEM.
     */
    public function parseWebhook(array $headers, string $rawBody): WebhookEvent
    {
        $webhookId = (string) $this->config->webhook('id', '');
        if ($webhookId === '') {
            throw new InvalidWebhookSignature(InvalidWebhookSignature::NOT_CONFIGURED);
        }

        $transmissionId = Headers::get($headers, 'paypal-transmission-id');
        $time = Headers::get($headers, 'paypal-transmission-time');
        $signature = Headers::get($headers, 'paypal-transmission-sig');
        $certUrl = Headers::get($headers, 'paypal-cert-url');
        $algorithm = Headers::get($headers, 'paypal-auth-algo') ?? 'SHA256withRSA';

        if ($transmissionId === null || $time === null || $signature === null || ($certUrl === null && empty($this->config->webhook('certificate')))) {
            throw new InvalidWebhookSignature(InvalidWebhookSignature::MALFORMED);
        }
        if (strcasecmp($algorithm, 'SHA256withRSA') !== 0) {
            throw new InvalidWebhookSignature(InvalidWebhookSignature::MALFORMED, "Unsupported algorithm {$algorithm}.");
        }

        $sent = strtotime($time);
        if ($sent === false || abs(time() - $sent) > (int) $this->config->webhook('tolerance_seconds', 300)) {
            throw new InvalidWebhookSignature(InvalidWebhookSignature::OUTSIDE_TOLERANCE);
        }

        $manifest = sprintf('%s|%s|%s|%u', $transmissionId, $time, $webhookId, crc32($rawBody));
        $certificate = $this->certificate($certUrl);
        $decoded = base64_decode($signature, true);

        if ($decoded === false || openssl_verify($manifest, $decoded, $certificate, OPENSSL_ALGO_SHA256) !== 1) {
            throw new InvalidWebhookSignature(InvalidWebhookSignature::MISMATCH);
        }

        $event = json_decode($rawBody, true);
        if (! is_array($event) || ! isset($event['id'], $event['event_type'])) {
            throw new InvalidWebhookSignature(InvalidWebhookSignature::MALFORMED);
        }

        $resource = (array) ($event['resource'] ?? []);
        $type = (string) ($event['resource_type'] ?? 'unknown');
        $orderId = $resource['supplementary_data']['related_ids']['order_id'] ?? null;

        return new WebhookEvent(
            gateway: $this->name(),
            id: (string) $event['id'],
            type: (string) $event['event_type'],
            resourceType: $type,
            resourceId: (string) ($resource['id'] ?? ''),
            paymentId: $type === 'checkout-order' ? (isset($resource['id']) ? (string) $resource['id'] : null) : (is_string($orderId) ? $orderId : null),
            payload: $event,
        );
    }

    /** A capture or authorization event names its order through the resource's `up` link. */
    public function paymentIdForEvent(WebhookEvent $event): ?string
    {
        if ($event->paymentId !== null) {
            return $event->paymentId;
        }

        $path = match ($event->resourceType) {
            'capture' => '/v2/payments/captures/',
            'authorization' => '/v2/payments/authorizations/',
            default => null,
        };
        if ($path === null || ! preg_match('/^[A-Za-z0-9-]{1,64}$/', $event->resourceId)) {
            return null;
        }

        $resource = $this->http->request('GET', $path . $event->resourceId, $this->headers(), allowNotFound: true) ?? [];
        foreach ((array) ($resource['links'] ?? []) as $link) {
            if (($link['rel'] ?? null) === 'up' && preg_match('#/v2/checkout/orders/([A-Za-z0-9-]+)$#', (string) ($link['href'] ?? ''), $m)) {
                return $m[1];
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $order */
    public static function mapStatus(array $order): PaymentStatus
    {
        $payments = (array) ($order['purchase_units'][0]['payments'] ?? []);
        $capture = $payments['captures'][0]['status'] ?? null;
        $authorization = $payments['authorizations'][0]['status'] ?? null;

        return match ($order['status'] ?? null) {
            'CREATED', 'SAVED' => PaymentStatus::Created,
            'PAYER_ACTION_REQUIRED' => PaymentStatus::RequiresAction,
            'APPROVED' => PaymentStatus::Approved,
            'VOIDED' => PaymentStatus::Canceled,
            'COMPLETED' => match (true) {
                $capture === 'COMPLETED' => PaymentStatus::Captured,
                $capture === 'PARTIALLY_REFUNDED' => PaymentStatus::PartiallyRefunded,
                $capture === 'REFUNDED' => PaymentStatus::Refunded,
                $capture === 'PENDING' => PaymentStatus::Processing,
                in_array($capture, ['DECLINED', 'FAILED'], true) => PaymentStatus::Failed,
                $authorization === 'CREATED' || $authorization === 'PENDING' => PaymentStatus::Authorized,
                $authorization === 'VOIDED' || $authorization === 'EXPIRED' => PaymentStatus::Canceled,
                $authorization === 'DENIED' => PaymentStatus::Failed,
                default => PaymentStatus::Processing,
            },
            default => PaymentStatus::Unknown,
        };
    }

    /** @param  array<string, mixed>  $order */
    private function toPayment(array $order, bool $returnsToMerchant): Payment
    {
        if (! isset($order['id'])) {
            throw new GatewayException('PayPal returned an order without id.');
        }

        $unit = (array) ($order['purchase_units'][0] ?? []);
        $amount = (array) ($unit['amount'] ?? []);
        $currency = (string) ($amount['currency_code'] ?? 'USD');
        $capture = (array) ($unit['payments']['captures'][0] ?? []);
        $status = self::mapStatus($order);

        $approve = null;
        foreach ((array) ($order['links'] ?? []) as $link) {
            if (in_array($link['rel'] ?? null, ['approve', 'payer-action'], true) && is_string($link['href'] ?? null)) {
                $approve = $link['href'];
            }
        }

        return new Payment(
            gateway: $this->name(),
            id: (string) $order['id'],
            status: $status,
            amount: Money::fromDecimal((string) ($amount['value'] ?? '0'), $currency),
            reference: isset($unit['custom_id']) ? (string) $unit['custom_id'] : null,
            providerStatus: isset($order['status']) ? (string) $order['status'] : null,
            providerStatusDetail: isset($capture['status']) ? (string) $capture['status'] : null,
            transactionId: isset($capture['id']) ? (string) $capture['id'] : null,
            capturedAmount: isset($capture['amount']['value']) ? Money::fromDecimal((string) $capture['amount']['value'], $currency) : null,
            nextAction: $approve !== null && in_array($status, [PaymentStatus::Created, PaymentStatus::RequiresAction], true)
                ? NextAction::redirect($approve, $returnsToMerchant)
                : null,
            raw: $order,
        );
    }

    /** Read the order again after a write: the write response is not the whole order. */
    private function reload(string $paymentId, string $after): Payment
    {
        $order = $this->http->request('GET', '/v2/checkout/orders/' . $paymentId, $this->headers(), allowNotFound: true);
        if ($order === null) {
            throw new GatewayException("Order {$paymentId} vanished after {$after}.");
        }

        return $this->toPayment($order, false);
    }

    private function certificate(?string $url): string
    {
        $pinned = (string) $this->config->webhook('certificate', '');
        if ($pinned !== '') {
            return $pinned;
        }

        $parts = parse_url((string) $url);
        $hosts = (array) $this->config->webhook('cert_hosts', self::DEFAULT_CERT_HOSTS);
        // Harbor serves its public test certificate from the emulator host, over HTTP.
        $allowHttp = $this->config->isEmulator();
        if ($allowHttp) {
            $hosts[] = (string) parse_url($this->config->emulatorApiUrl(), PHP_URL_HOST);
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['https', 'http'], true) || ! in_array($host, array_map('strtolower', $hosts), true)) {
            throw new InvalidWebhookSignature(InvalidWebhookSignature::UNTRUSTED_CERTIFICATE, "Certificate host '{$host}' is not allowed.");
        }
        if (($parts['scheme'] ?? '') === 'http' && ! $allowHttp) {
            throw new InvalidWebhookSignature(InvalidWebhookSignature::UNTRUSTED_CERTIFICATE, 'Certificates are only fetched over HTTPS.');
        }

        if (isset($this->certificates[(string) $url])) {
            return $this->certificates[(string) $url];
        }

        try {
            $pem = (string) $this->client->request('GET', (string) $url, [RequestOptions::TIMEOUT => 5, RequestOptions::HTTP_ERRORS => true])->getBody();
        } catch (Throwable $e) {
            throw new InvalidWebhookSignature(InvalidWebhookSignature::UNTRUSTED_CERTIFICATE, 'Could not download the signing certificate: ' . $e->getMessage());
        }

        if (openssl_x509_read($pem) === false) {
            throw new InvalidWebhookSignature(InvalidWebhookSignature::UNTRUSTED_CERTIFICATE, 'The signing certificate is not a valid X.509 PEM.');
        }

        return $this->certificates[(string) $url] = $pem;
    }

    /** @return array<string, string> */
    private function headers(?string $requestId = null): array
    {
        $headers = ['Authorization' => 'Bearer ' . $this->accessToken(), 'Prefer' => 'return=representation'];

        return $requestId !== null ? $headers + ['PayPal-Request-Id' => substr($requestId, 0, 108)] : $headers;
    }

    private function accessToken(): string
    {
        $fixed = $this->config->credentialOrNull('access_token');
        if ($fixed !== null) {
            return $fixed;
        }

        if ($this->token !== null && time() < $this->tokenExpiresAt) {
            return $this->token;
        }

        $body = $this->http->request('POST', '/v1/oauth2/token', [
            'Authorization' => 'Basic ' . base64_encode($this->config->credential('client_id') . ':' . $this->config->credential('client_secret')),
        ], form: ['grant_type' => 'client_credentials']) ?? [];

        if (! isset($body['access_token'])) {
            throw new GatewayException('PayPal did not return an access token.');
        }

        $this->token = (string) $body['access_token'];
        $this->tokenExpiresAt = time() + max(60, (int) ($body['expires_in'] ?? 3600) - 60);

        return $this->token;
    }
}
