<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Http;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ronu\PaymentGateways\Config\GatewayConfig;
use Ronu\PaymentGateways\Exceptions\GatewayException;
use Ronu\PaymentGateways\Services;

/**
 * JSON-over-HTTP with the error and retry policy every driver shares.
 *
 * Guzzle never throws on a status (`http_errors: false`): the status decides
 * here whether the failure is retryable, so all drivers answer the same
 * question the same way.
 *
 * Retries resend the SAME request — same idempotency key — so the provider
 * replays instead of charging twice. A write without an idempotency key is only
 * retried when the connection failed before anything was sent.
 *
 * Logs say what was called and how it went: method, path without query, status,
 * duration, attempt, provider code. Never headers, bodies or query strings.
 */
final class Transport
{
    /** Headers that make a write safe to resend. */
    public const IDEMPOTENCY_HEADERS = ['idempotency-key', 'x-idempotency-key', 'paypal-request-id'];

    private ClientInterface $client;

    private LoggerInterface $logger;

    /** @var Closure(int): void */
    private Closure $sleep;

    /**
     * @param  callable(array<string, mixed>): ?string  $errorCode  Pulls the provider's error code out of a body.
     * @param  int  $retries  Extra attempts after the first (408, 429, 5xx, network).
     * @param  int  $retryDelayMs  First backoff; doubles each attempt. Retry-After wins when the provider sends it.
     * @param  int  $maxRetryDelayMs  A longer wait is not worth holding a web request: fail retryable instead.
     * @param  (Closure(int): void)|null  $sleep
     */
    public function __construct(
        private readonly string $baseUrl,
        ?ClientInterface $client = null,
        private readonly float $timeout = 15.0,
        private readonly float $connectTimeout = 5.0,
        private $errorCode = null,
        private readonly int $retries = 0,
        private readonly int $retryDelayMs = 250,
        private readonly int $maxRetryDelayMs = 5_000,
        ?LoggerInterface $logger = null,
        private readonly string $gateway = 'gateway',
        ?Closure $sleep = null,
    ) {
        $this->client = $client ?? new Client();
        $this->logger = $logger ?? new NullLogger();
        $this->sleep = $sleep ?? static function (int $milliseconds): void {
            usleep($milliseconds * 1000);
        };
    }

    /**
     * The transport a driver gets from its config (`http` section) and the Services.
     *
     * @param  callable(array<string, mixed>): ?string  $errorCode
     */
    public static function for(GatewayConfig $config, string $baseUrl, Services $services, callable $errorCode): self
    {
        return new self(
            $baseUrl,
            $services->http,
            $config->timeout(),
            $config->connectTimeout(),
            $errorCode,
            $config->retries(),
            $config->retryDelayMs(),
            $config->maxRetryDelayMs(),
            $services->logger,
            $config->name,
            $services->sleep,
        );
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|object|null  $json
     * @param  array<string, mixed>|null  $form  application/x-www-form-urlencoded (Stripe)
     * @param  bool|null  $idempotent  Safe to resend after a response was lost. Null: GET, or a write
     *                                 carrying one of IDEMPOTENCY_HEADERS.
     * @return array<string, mixed>|null  Null on 404 when $allowNotFound, or on an empty 2xx body.
     */
    public function request(
        string $method,
        string $path,
        array $headers = [],
        array|object|null $json = null,
        ?array $form = null,
        bool $allowNotFound = false,
        ?bool $idempotent = null,
    ): ?array {
        $options = [
            RequestOptions::HEADERS => $headers + ['Accept' => 'application/json'],
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::TIMEOUT => $this->timeout,
            RequestOptions::CONNECT_TIMEOUT => $this->connectTimeout,
        ];
        if ($json !== null) {
            $options[RequestOptions::JSON] = $json;
        }
        if ($form !== null) {
            $options[RequestOptions::FORM_PARAMS] = $form;
        }

        $idempotent ??= strtoupper($method) === 'GET' || $this->hasIdempotencyKey($headers);
        $url = rtrim($this->baseUrl, '/') . $path;
        $logPath = (string) strtok($path, '?');

        for ($attempt = 1; ; $attempt++) {
            $started = microtime(true);
            try {
                $response = $this->client->request($method, $url, $options);
            } catch (GuzzleException $e) {
                // Nothing was sent when the connection failed: any request may go again.
                $error = new GatewayException('Payment provider ' . ($e instanceof ConnectException ? 'unreachable' : 'request failed') . ': ' . $e->getMessage(), true, null, null, null, $e);
                $delay = $attempt <= $this->retries && ($idempotent || $e instanceof ConnectException) ? $this->backoff($attempt, null) : null;
                $this->log($error, $method, $logPath, $attempt, $started, $delay);
                if ($delay === null) {
                    throw $error;
                }
                ($this->sleep)($delay);

                continue;
            }

            $status = $response->getStatusCode();
            $raw = (string) $response->getBody();
            $body = $raw === '' ? null : json_decode($raw, true);
            $body = is_array($body) ? $body : null;

            if (($status >= 200 && $status < 300) || ($status === 404 && $allowNotFound)) {
                $this->logger->info('payment-gateways: {gateway} {method} {path} → {status}', $this->context($method, $logPath, $attempt, $started) + ['status' => $status]);

                return $status === 404 ? null : $body;
            }

            $code = $body !== null && $this->errorCode ? ($this->errorCode)($body) : null;
            $retryable = $status === 408 || $status === 429 || $status >= 500;
            $error = new GatewayException(
                sprintf('Payment provider answered HTTP %d%s.', $status, $code ? " ({$code})" : ''),
                $retryable,
                $status,
                $code,
                $body,
            );

            $delay = $retryable && $idempotent && $attempt <= $this->retries ? $this->backoff($attempt, $response) : null;
            $this->log($error, $method, $logPath, $attempt, $started, $delay);
            if ($delay === null) {
                throw $error;
            }
            ($this->sleep)($delay);
        }
    }

    /** @param  array<string, string>  $headers */
    private function hasIdempotencyKey(array $headers): bool
    {
        foreach ($headers as $name => $value) {
            if ($value !== '' && in_array(strtolower((string) $name), self::IDEMPOTENCY_HEADERS, true)) {
                return true;
            }
        }

        return false;
    }

    /** Milliseconds to wait before the next attempt, or null when the wait is not worth it. */
    private function backoff(int $attempt, ?ResponseInterface $response): ?int
    {
        $retryAfter = $response?->getHeaderLine('Retry-After') ?? '';
        if ($retryAfter !== '') {
            $seconds = ctype_digit($retryAfter) ? (int) $retryAfter : (($at = strtotime($retryAfter)) !== false ? max(0, $at - time()) : null);
            if ($seconds !== null) {
                $delay = $seconds * 1000;

                return $delay <= $this->maxRetryDelayMs ? $delay : null;
            }
        }

        $delay = $this->retryDelayMs * (2 ** ($attempt - 1));
        $jitter = $this->retryDelayMs > 0 ? random_int(0, intdiv($this->retryDelayMs, 2)) : 0;

        return min($this->maxRetryDelayMs, $delay + $jitter);
    }

    private function log(GatewayException $error, string $method, string $path, int $attempt, float $started, ?int $retryInMs): void
    {
        $context = $this->context($method, $path, $attempt, $started) + [
            'status' => $error->httpStatus,
            'provider_code' => $error->providerCode,
            'retryable' => $error->retryable,
        ];

        if ($retryInMs !== null) {
            $this->logger->notice('payment-gateways: {gateway} {method} {path} failed ({status}), retrying in {retry_in_ms} ms', $context + ['retry_in_ms' => $retryInMs]);
        } else {
            $this->logger->warning('payment-gateways: {gateway} {method} {path} failed ({status})', $context);
        }
    }

    /** @return array<string, mixed> */
    private function context(string $method, string $path, int $attempt, float $started): array
    {
        return [
            'gateway' => $this->gateway,
            'method' => strtoupper($method),
            'path' => $path,
            'attempt' => $attempt,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }
}
