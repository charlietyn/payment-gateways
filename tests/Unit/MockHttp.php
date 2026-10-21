<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/** A Guzzle client that answers from a queue and records every request. */
final class MockHttp
{
    /** @var list<array{request: RequestInterface}> */
    public array $history = [];

    public MockHandler $handler;

    public Client $client;

    /** @param  list<Response|\Throwable>  $responses */
    public function __construct(array $responses = [])
    {
        $this->handler = new MockHandler($responses);
        $stack = HandlerStack::create($this->handler);
        $stack->push(Middleware::history($this->history));
        $this->client = new Client(['handler' => $stack]);
    }

    /** @param  array<string, mixed>|list<mixed>  $body */
    public static function json(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function request(int $index): RequestInterface
    {
        return $this->history[$index]['request'];
    }

    /** @return array<string, mixed> */
    public function jsonBody(int $index): array
    {
        return (array) json_decode((string) $this->request($index)->getBody(), true);
    }

    /** @return array<string, mixed> */
    public function formBody(int $index): array
    {
        parse_str((string) $this->request($index)->getBody(), $out);

        return $out;
    }
}
