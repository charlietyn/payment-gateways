<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Contracts;

use Ronu\PaymentGateways\WebhookEvent;

/**
 * Every provider redelivers webhooks: on timeouts, on 5xx, sometimes for no reason.
 *
 *     $event = $gateway->parseWebhook($headers, $rawBody);
 *     if ($events->alreadyProcessed($event)) {
 *         return response('', 200);          // done before: acknowledge and stop
 *     }
 *     $this->handle($event);                 // may throw: the provider will retry
 *     $events->markProcessed($event);
 *
 * Mark AFTER the handler succeeds, so a failure is retried rather than lost. Two
 * copies arriving at the same instant may both run: keep fulfilment idempotent
 * (Payment::matches() + "once" in your own store).
 */
interface EventDeduplicator
{
    public function alreadyProcessed(WebhookEvent $event): bool;

    public function markProcessed(WebhookEvent $event): void;
}
