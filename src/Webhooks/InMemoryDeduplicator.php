<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Webhooks;

use Ronu\PaymentGateways\Contracts\EventDeduplicator;
use Ronu\PaymentGateways\WebhookEvent;

/** One process only: tests and long-running workers. Use CacheDeduplicator behind a web server. */
final class InMemoryDeduplicator implements EventDeduplicator
{
    /** @var array<string, true> */
    private array $processed = [];

    public function alreadyProcessed(WebhookEvent $event): bool
    {
        return isset($this->processed[$event->gateway . '|' . $event->id]);
    }

    public function markProcessed(WebhookEvent $event): void
    {
        $this->processed[$event->gateway . '|' . $event->id] = true;
    }
}
