<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways;

use Closure;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

/**
 * The objects a gateway works with, kept out of the config so the config stays a
 * plain, cacheable array:
 *
 *     GatewayFactory::make('paypal', $config, new Services(cache: $psr16Cache));
 *
 * All optional. Without a cache, PayPal keeps its OAuth token per process only.
 */
final readonly class Services
{
    /** @var Closure(int): void */
    public Closure $sleep;

    public LoggerInterface $logger;

    /**
     * @param  ClientInterface|null  $http  Your Guzzle client (middlewares, proxies, mocks in tests).
     * @param  CacheInterface|null  $cache  PSR-16: OAuth tokens shared between processes.
     * @param  LoggerInterface|null  $logger  PSR-3: one line per provider call, never headers or bodies.
     * @param  (Closure(int): void)|null  $sleep  Waits N milliseconds between retries. Tests pass a no-op.
     */
    public function __construct(
        public ?ClientInterface $http = null,
        public ?CacheInterface $cache = null,
        ?LoggerInterface $logger = null,
        ?Closure $sleep = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->sleep = $sleep ?? static function (int $milliseconds): void {
            usleep($milliseconds * 1000);
        };
    }

    /** What GatewayFactory::make() accepts as its third argument. */
    public static function from(ClientInterface|self|null $value): self
    {
        return $value instanceof self ? $value : new self($value);
    }
}
