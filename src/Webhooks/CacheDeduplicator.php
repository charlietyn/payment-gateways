<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Webhooks;

use Psr\SimpleCache\CacheInterface;
use Ronu\PaymentGateways\Contracts\EventDeduplicator;
use Ronu\PaymentGateways\WebhookEvent;

/**
 * Processed event ids in a PSR-16 cache shared by every web worker.
 *
 * The TTL must outlive the provider's redelivery window: Stripe retries for up to
 * three days, PayPal and Mercado Pago for a few. Seven days by default.
 */
final class CacheDeduplicator implements EventDeduplicator
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly int $ttlSeconds = 7 * 24 * 3600,
        private readonly string $prefix = 'ronu.payment_gateways.webhook.',
    ) {}

    public function alreadyProcessed(WebhookEvent $event): bool
    {
        return $this->cache->has($this->key($event));
    }

    public function markProcessed(WebhookEvent $event): void
    {
        $this->cache->set($this->key($event), 1, $this->ttlSeconds);
    }

    /** Hashed: provider ids may hold characters PSR-16 keys forbid. */
    private function key(WebhookEvent $event): string
    {
        return $this->prefix . sha1($event->gateway . '|' . $event->id);
    }
}
