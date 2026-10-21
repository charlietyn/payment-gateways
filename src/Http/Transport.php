<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Http;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Ronu\PaymentGateways\Exceptions\GatewayException;

/**
 * JSON-over-HTTP with the error policy every driver shares.
 *
 * Guzzle never throws on a status (`http_errors: false`): the status decides
 * here whether the failure is retryable, so all three drivers answer the same
 * question the same way.
 */
final class Transport
{
    private ClientInterface $client;

    /**
     * @param  callable(array<string, mixed>): ?string  $errorCode  Pulls the provider's error code out of a body.
     */
    public function __construct(
        private readonly string $baseUrl,
        ?ClientInterface $client = null,
        private readonly float $timeout = 15.0,
        private readonly float $connectTimeout = 5.0,
        private $errorCode = null,
    ) {
        $this->client = $client ?? new Client();
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|object|null  $json
     * @param  array<string, mixed>|null  $form  application/x-www-form-urlencoded (Stripe)
     * @return array<string, mixed>|null  Null on 404 when $allowNotFound, or on an empty 2xx body.
     */
    public function request(
        string $method,
        string $path,
        array $headers = [],
        array|object|null $json = null,
        ?array $form = null,
        bool $allowNotFound = false,
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

        try {
            $response = $this->client->request($method, rtrim($this->baseUrl, '/') . $path, $options);
        } catch (ConnectException $e) {
            throw new GatewayException('Payment provider unreachable: ' . $e->getMessage(), true, null, null, null, $e);
        } catch (GuzzleException $e) {
            throw new GatewayException('Payment provider request failed: ' . $e->getMessage(), true, null, null, null, $e);
        }

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $body = $raw === '' ? null : json_decode($raw, true);
        $body = is_array($body) ? $body : null;

        if ($status === 404 && $allowNotFound) {
            return null;
        }

        if ($status >= 200 && $status < 300) {
            return $body;
        }

        $code = $body !== null && $this->errorCode ? ($this->errorCode)($body) : null;
        $retryable = $status === 408 || $status === 429 || $status >= 500;

        throw new GatewayException(
            sprintf('Payment provider answered HTTP %d%s.', $status, $code ? " ({$code})" : ''),
            $retryable,
            $status,
            $code,
            $body,
        );
    }
}
