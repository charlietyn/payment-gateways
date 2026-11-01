<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Testing;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Ronu\PaymentGateways\Money;

/**
 * Mercado Pago's Orders API in memory, for your tests: a Guzzle handler that keeps
 * orders, honours X-Idempotency-Key and answers with Mercado Pago's bodies and errors
 * (the ones the Payment Emulator Lab's Plaza profile reproduces).
 *
 *     $fake = new FakeMercadoPago();
 *     $gateway = GatewayFactory::make('plaza', [...], $fake->client());
 *     $payment = $gateway->createPayment($request);    // action_required / waiting_payment
 *     $fake->pay($payment->id);                        // the buyer pays on the hosted page
 *     [$headers, $body] = $fake->signedWebhook($payment->id, $secret);
 *
 * Any host works and a path prefix is ignored, so the `plaza` preset (…/plaza/v1/orders)
 * and `mercadopago` (api.mercadopago.com/v1/orders) both reach it. Checkout Pro
 * (preferences) is not simulated: with the `plaza` preset createCheckout() uses an order.
 *
 * What it does, as Mercado Pago does it:
 *  - a card with a token is processed at once (manual capture: waiting_capture);
 *    no method or a ticket waits for the buyer, with a ticket_url;
 *  - capture only an authorized order, cancel only one still open, refund only a
 *    processed one; otherwise 400 `invalid_state`;
 *  - a partial refund names the transaction; `{}` refunds what remains;
 *  - the same idempotency key replays the first answer, with another body it is 409.
 *
 * In Laravel: `$this->app->instance(PaymentGatewaysServiceProvider::HTTP, $fake->client());`
 */
final class FakeMercadoPago
{
    /** @var array<string, array<string, mixed>> Order id => order, as the API returns it. */
    private array $orders = [];

    /**
     * Checkout Pro: preference id (`pref-<n>`) => the preference and, once the buyer attempted it,
     * its Payments API payment (numeric id `<n>`). Same semantics as the Plaza emulator profile.
     *
     * @var array<string, array{preference: array<string, mixed>, payment: array<string, mixed>|null}>
     */
    private array $preferences = [];

    /** @var array<string, array{hash: string, status: int, body: array<string, mixed>}> */
    private array $replies = [];

    /** @var list<array{int, array<string, mixed>}> */
    private array $failures = [];

    /** @var list<RequestInterface> Every request received, in order. */
    public array $requests = [];

    public function __construct(private readonly string $currency = 'ARS') {}

    /** A Guzzle client whose requests all land here. */
    public function client(): Client
    {
        return new Client(['handler' => HandlerStack::create($this)]);
    }

    /** @param  array<string, mixed>  $options */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $this->requests[] = $request;
        [$status, $body] = $this->handle($request);

        return Create::promiseFor(new Response($status, ['Content-Type' => 'application/json', 'X-Request-Id' => bin2hex(random_bytes(8))],
            json_encode($body === [] ? (object) [] : $body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));
    }

    /**
     * The next request fails with this answer (500 by default), before anything is stored.
     *
     * @param  array<string, mixed>|null  $body
     */
    public function failNext(int $status = 500, ?array $body = null): self
    {
        $this->failures[] = [$status, $body ?? self::error($status, $status >= 500 ? 'internal_error' : 'bad_request', 'Simulated failure.')];

        return $this;
    }

    /** The buyer pays an open order (card on the hosted page, or the ticket), or a Checkout Pro preference. */
    public function pay(string $orderId, string $method = 'visa'): void
    {
        if (isset($this->preferences[$orderId])) {
            $this->attempt($orderId, 'approved', 'accredited');

            return;
        }
        $order = $this->open($orderId);
        $manual = ($order['capture_mode'] ?? 'automatic') === 'manual';
        $order['transactions']['payments'][0]['payment_method'] = ['id' => $method, 'type' => $method === 'pagofacil' ? 'ticket' : 'credit_card'];
        $this->orders[$orderId] = $manual
            ? self::withStatus($order, 'action_required', 'waiting_capture')
            : self::withStatus($order, 'processed', 'accredited');
    }

    /** The buyer's card is rejected. */
    public function decline(string $orderId): void
    {
        if (isset($this->preferences[$orderId])) {
            $this->attempt($orderId, 'rejected', 'cc_rejected_other_reason');

            return;
        }
        $this->orders[$orderId] = self::withStatus($this->open($orderId), 'failed', 'failed');
    }

    /** The order ran out of time unpaid. */
    public function expire(string $orderId): void
    {
        $this->orders[$orderId] = self::withStatus($this->open($orderId), 'expired', 'expired');
    }

    /** The buyer disputed a processed order. */
    public function chargeBack(string $orderId): void
    {
        $order = $this->orders[$orderId] ?? throw new \InvalidArgumentException("No order {$orderId}.");
        $this->orders[$orderId] = self::withStatus($order, 'charged_back', 'in_process');
    }

    /** @return array<string, mixed>|null  The order as GET /v1/orders/{id} returns it. */
    public function order(string $orderId): ?array
    {
        return $this->orders[$orderId] ?? null;
    }

    /** @return array<string, array<string, mixed>> */
    public function orders(): array
    {
        return $this->orders;
    }

    /**
     * An order notification signed the way Mercado Pago signs it, for parseWebhook().
     *
     * @return array{0: array<string, string>, 1: string}  Headers and raw body.
     */
    public function signedWebhook(string $orderId, string $secret, string $action = 'order.updated', ?int $timestamp = null): array
    {
        $timestamp ??= time();
        $requestId = bin2hex(random_bytes(16));
        $body = json_encode([
            'action' => $action,
            'api_version' => 'v1',
            'data' => ['id' => $orderId],
            'date_created' => gmdate('Y-m-d\TH:i:s\Z', $timestamp),
            'id' => 'notif_' . bin2hex(random_bytes(8)),
            'live_mode' => false,
            'type' => 'order',
            'user_id' => 0,
        ], JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', "id:{$orderId};request-id:{$requestId};ts:{$timestamp};", $secret);

        return [['x-signature' => "ts={$timestamp},v1={$signature}", 'x-request-id' => $requestId, 'content-type' => 'application/json'], $body];
    }

    /** @return array{int, array<string, mixed>} */
    private function handle(RequestInterface $request): array
    {
        if ($this->failures !== []) {
            return array_shift($this->failures);
        }
        if (! str_starts_with($request->getHeaderLine('Authorization'), 'Bearer ') || strlen($request->getHeaderLine('Authorization')) <= 7) {
            return [401, self::error(401, 'unauthorized', 'Invalid access token.')];
        }

        $method = $request->getMethod();
        $checkoutPro = $this->checkoutPro($request);
        if ($checkoutPro !== null) {
            return $checkoutPro;
        }
        if (! preg_match('#/v1/orders(?:/([A-Za-z0-9_-]+)(?:/(capture|cancel|refund))?)?$#', $request->getUri()->getPath(), $route)) {
            return [404, self::error(404, 'not_found', 'Resource not found.')];
        }
        $id = $route[1] ?? '';
        $action = $route[2] ?? '';

        if ($method === 'GET' && $id !== '' && $action === '') {
            return isset($this->orders[$id]) ? [200, $this->orders[$id]] : [404, self::error(404, 'not_found', 'Order not found.')];
        }
        if ($method !== 'POST' || ($id === '') !== ($action === '')) {
            return [404, self::error(404, 'not_found', 'Resource not found.')];
        }

        $key = $request->getHeaderLine('X-Idempotency-Key');
        if ($key === '') {
            return [400, self::error(400, 'bad_request', 'X-Idempotency-Key is required.', '2067')];
        }
        $raw = (string) $request->getBody();
        $hash = hash('sha256', "{$method} {$id}/{$action} {$raw}");
        $replay = $this->replies[$key] ?? null;
        if ($replay !== null) {
            return $replay['hash'] === $hash
                ? [$replay['status'], $replay['body']]
                : [409, self::error(409, 'idempotency_error', 'The idempotency key was used with another request.', '2062')];
        }

        $body = json_decode($raw === '' ? '{}' : $raw, true);
        [$status, $answer] = is_array($body) ? match ($action) {
            '' => $this->create($body),
            'capture' => $this->capture($id),
            'cancel' => $this->cancel($id),
            default => $this->refund($id, $body),
        } : [400, self::error(400, 'bad_request', 'The body is not JSON.')];

        if ($status < 500) {
            $this->replies[$key] = ['hash' => $hash, 'status' => $status, 'body' => $answer];
        }

        return [$status, $answer];
    }

    /**
     * Checkout Pro routes: preferences, numeric payments (absent until attempted), search and refunds.
     *
     * @return array{int, array<string, mixed>}|null  Null when the request is not Checkout Pro.
     */
    private function checkoutPro(RequestInterface $request): ?array
    {
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();
        if ($method === 'POST' && preg_match('#/checkout/preferences$#', $path)) {
            $body = json_decode((string) $request->getBody(), true);
            $items = is_array($body) ? ($body['items'] ?? null) : null;
            if (! is_array($items) || $items === []) {
                return [400, self::error(400, 'bad_request', 'items is required')];
            }
            $minor = 0;
            foreach ($items as $item) {
                $minor += (int) round(((float) ($item['unit_price'] ?? 0)) * 100) * (int) ($item['quantity'] ?? 1);
            }
            $id = 'pref-' . random_int(10_000_000_000_000, 99_999_999_999_999);
            $this->preferences[$id] = ['preference' => [
                'id' => $id,
                'items' => $items,
                'external_reference' => (string) ($body['external_reference'] ?? ''),
                'back_urls' => $body['back_urls'] ?? [],
                'auto_return' => $body['auto_return'] ?? '',
                'init_point' => "https://fake.checkout.test/plaza/checkout/{$id}",
                'transaction_minor' => $minor,
                'currency_id' => (string) ($items[0]['currency_id'] ?? $this->currency),
            ], 'payment' => null];

            return [201, $this->preferences[$id]['preference']];
        }
        if ($method === 'GET' && preg_match('#/checkout/preferences/(pref-[0-9]+)$#', $path, $m)) {
            return isset($this->preferences[$m[1]]) ? [200, $this->preferences[$m[1]]['preference']] : [404, self::error(404, 'not_found', 'Preference not found.')];
        }
        if ($method === 'GET' && preg_match('#/v1/payments/search$#', $path)) {
            parse_str($request->getUri()->getQuery(), $query);
            $results = [];
            foreach (array_reverse($this->preferences) as $entry) {
                if ($entry['payment'] !== null && $entry['preference']['external_reference'] === ($query['external_reference'] ?? null)) {
                    $results[] = $entry['payment'];
                }
            }

            return [200, ['paging' => ['total' => count($results), 'limit' => 30, 'offset' => 0], 'results' => $results]];
        }
        if (preg_match('#/v1/payments/([0-9]+)(/refunds)?$#', $path, $m)) {
            $id = 'pref-' . $m[1];
            $payment = $this->preferences[$id]['payment'] ?? null;
            if ($payment === null) {
                return [404, self::error(404, 'not_found', 'Payment not found.')];
            }
            if ($method === 'GET' && ! isset($m[2])) {
                return [200, $payment];
            }
            if ($method === 'POST' && isset($m[2])) {
                $body = json_decode((string) $request->getBody(), true);
                $amount = is_array($body) && isset($body['amount']) ? (float) $body['amount'] : $payment['transaction_amount'] - $payment['transaction_amount_refunded'];
                $refund = ['id' => random_int(1_000_000, 9_999_999), 'payment_id' => (int) $m[1], 'amount' => $amount, 'status' => 'approved'];
                $payment['refunds'][] = $refund;
                $payment['transaction_amount_refunded'] += $amount;
                $full = $payment['transaction_amount_refunded'] >= $payment['transaction_amount'];
                $payment['status'] = $full ? 'refunded' : 'approved';
                $payment['status_detail'] = $full ? 'refunded' : 'partially_refunded';
                $this->preferences[$id]['payment'] = $payment;

                return [201, $refund];
            }
        }

        return null;
    }

    private function attempt(string $preferenceId, string $status, string $detail): void
    {
        $preference = $this->preferences[$preferenceId]['preference'];
        $this->preferences[$preferenceId]['payment'] = [
            'id' => (int) substr($preferenceId, 5),
            'status' => $status,
            'status_detail' => $detail,
            'transaction_amount' => $preference['transaction_minor'] / 100,
            'transaction_amount_refunded' => 0,
            'currency_id' => $preference['currency_id'],
            'external_reference' => $preference['external_reference'],
            'refunds' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{int, array<string, mixed>}
     */
    private function create(array $body): array
    {
        $currency = strtoupper((string) ($body['currency_id'] ?? $this->currency));
        $payment = (array) ($body['transactions']['payments'][0] ?? []);
        try {
            $total = Money::fromDecimal((string) ($body['total_amount'] ?? ''), $currency);
            $amount = Money::fromDecimal((string) ($payment['amount'] ?? $body['total_amount']), $currency);
        } catch (\InvalidArgumentException) {
            return [400, self::error(400, 'bad_request', 'total_amount must be a decimal string.', '2034')];
        }
        if ($total->minor <= 0 || ! $amount->equals($total)) {
            return [400, self::error(400, 'invalid_transaction_amount', 'A positive payment amount must equal total_amount.', '2004')];
        }

        $id = 'ORD' . strtoupper(bin2hex(random_bytes(13)));
        $method = (array) ($payment['payment_method'] ?? ['id' => 'pagofacil', 'type' => 'ticket']);
        $card = in_array($method['type'] ?? null, ['credit_card', 'debit_card'], true) && ($method['token'] ?? '') !== '';
        $manual = ($body['capture_mode'] ?? 'automatic') === 'manual';
        unset($method['token']);
        if (! $card) {
            $method['ticket_url'] = "https://fake-mercadopago.test/tickets/{$id}";
        }

        $now = gmdate('Y-m-d\TH:i:s.v\Z');
        $order = [
            'id' => $id,
            'type' => (string) ($body['type'] ?? 'online'),
            'processing_mode' => (string) ($body['processing_mode'] ?? 'automatic'),
            'capture_mode' => $manual ? 'manual' : 'automatic',
            'external_reference' => isset($body['external_reference']) ? (string) $body['external_reference'] : null,
            'description' => $body['description'] ?? null,
            'total_amount' => $total->toDecimal(),
            'currency_id' => $currency,
            'transactions' => ['payments' => [[
                'id' => 'PAY' . strtoupper(bin2hex(random_bytes(13))),
                'amount' => $total->toDecimal(),
                'payment_method' => $method,
            ]]],
            'created_date' => $now,
            'last_updated_date' => $now,
        ];

        [$status, $detail] = match (true) {
            $card && $manual => ['action_required', 'waiting_capture'],
            $card => ['processed', 'accredited'],
            default => ['action_required', 'waiting_payment'],
        };
        $this->orders[$id] = self::withStatus($order, $status, $detail);

        return [201, $this->orders[$id]];
    }

    /** @return array{int, array<string, mixed>} */
    private function capture(string $id): array
    {
        $order = $this->orders[$id] ?? null;
        if ($order === null) {
            return [404, self::error(404, 'not_found', 'Order not found.')];
        }
        if ($order['status_detail'] !== 'waiting_capture') {
            return [400, self::error(400, 'invalid_state', 'Only an authorized order can be captured.', '2100')];
        }

        return [200, $this->orders[$id] = self::withStatus($order, 'processed', 'accredited')];
    }

    /** @return array{int, array<string, mixed>} */
    private function cancel(string $id): array
    {
        $order = $this->orders[$id] ?? null;
        if ($order === null) {
            return [404, self::error(404, 'not_found', 'Order not found.')];
        }
        if (! in_array($order['status'], ['created', 'action_required'], true)) {
            return [400, self::error(400, 'invalid_state', "An order that is {$order['status']} cannot be canceled.", '2100')];
        }

        return [200, $this->orders[$id] = self::withStatus($order, 'canceled', 'canceled')];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{int, array<string, mixed>}
     */
    private function refund(string $id, array $body): array
    {
        $order = $this->orders[$id] ?? null;
        if ($order === null) {
            return [404, self::error(404, 'not_found', 'Order not found.')];
        }
        if ($order['status'] !== 'processed') {
            return [400, self::error(400, 'invalid_state', "An order that is {$order['status']} cannot be refunded.", '2100')];
        }

        $currency = (string) $order['currency_id'];
        $payment = $order['transactions']['payments'][0];
        $refunded = 0;
        foreach ((array) ($order['transactions']['refunds'] ?? []) as $previous) {
            $refunded += Money::fromDecimal((string) $previous['amount'], $currency)->minor;
        }
        $remaining = Money::fromDecimal((string) $order['total_amount'], $currency)->minor - $refunded;

        $requested = $body['transactions'][0] ?? null;
        if (is_array($requested)) {
            if (($requested['id'] ?? null) !== $payment['id']) {
                return [400, self::error(400, 'transaction_not_found', 'Transaction does not belong to this order.', '2000')];
            }
            try {
                $minor = Money::fromDecimal((string) ($requested['amount'] ?? ''), $currency)->minor;
            } catch (\InvalidArgumentException) {
                return [400, self::error(400, 'bad_request', 'amount must be a decimal string.', '2034')];
            }
        } else {
            $minor = $remaining;
        }
        if ($minor <= 0 || $minor > $remaining) {
            return [400, self::error(400, 'invalid_amount', 'The refund is for more than remains captured.', '2004')];
        }

        $order['transactions']['refunds'][] = [
            'id' => 'REF' . strtoupper(bin2hex(random_bytes(13))),
            'transaction_id' => $payment['id'],
            'amount' => Money::of($minor, $currency)->toDecimal(),
            'status' => 'processed',
        ];
        $order = $minor === $remaining ? self::withStatus($order, 'refunded', 'refunded') : self::withStatus($order, 'processed', 'partially_refunded');

        return [201, $this->orders[$id] = $order];
    }

    /** @return array<string, mixed> */
    private function open(string $orderId): array
    {
        $order = $this->orders[$orderId] ?? throw new \InvalidArgumentException("No order {$orderId}.");
        if ($order['status'] !== 'action_required' || $order['status_detail'] !== 'waiting_payment') {
            throw new \LogicException("Order {$orderId} is {$order['status']}/{$order['status_detail']}, not waiting for the buyer.");
        }

        return $order;
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    private static function withStatus(array $order, string $status, string $detail): array
    {
        $order['status'] = $status;
        $order['status_detail'] = $detail;
        $order['transactions']['payments'][0]['status'] = $status;
        $order['transactions']['payments'][0]['status_detail'] = $detail;
        $order['last_updated_date'] = gmdate('Y-m-d\TH:i:s.v\Z');

        return $order;
    }

    /** @return array<string, mixed>  Mercado Pago's error body. */
    private static function error(int $status, string $error, string $message, string $cause = '2000'): array
    {
        return ['error' => $error, 'message' => $message, 'status' => $status, 'cause' => [['code' => $cause, 'description' => $message]]];
    }
}
