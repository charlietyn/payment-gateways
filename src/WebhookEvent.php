<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways;

/**
 * A verified provider notification. It names what changed; it is not the new
 * state. Re-read with Gateway::getPayment(Gateway::paymentIdForEvent($event)).
 *
 * $type is the provider's own name (`payment_intent.succeeded`); $kind the same for
 * every provider (EventType::PaymentCaptured).
 */
final readonly class WebhookEvent
{
    /** @var array<string, mixed> */
    public array $payload;

    /**
     * @param  string  $id  Provider event id — use it to drop redeliveries.
     * @param  string  $resourceType  order | payment_intent | charge | refund | capture | authorization | …
     * @param  string|null  $paymentId  The payment resource id when the event carries it directly.
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $gateway,
        public string $id,
        public string $type,
        public string $resourceType,
        public string $resourceId,
        public ?string $paymentId,
        array $payload,
        public EventType $kind = EventType::Unknown,
    ) {
        $this->payload = \Ronu\PaymentGateways\Support\SensitiveData::redact($payload);
    }
}
