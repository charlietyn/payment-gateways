<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways;

/**
 * The canonical payment lifecycle every driver maps its provider into.
 *
 * It is the Payment Emulator Lab's canonical model plus `processing` (Stripe's
 * asynchronous confirmation) and `partially_refunded`, because a consumer needs
 * to tell "some money went back" from "all of it did".
 */
enum PaymentStatus: string
{
    /** Exists, nothing attempted yet. */
    case Created = 'created';
    /** Waiting for the buyer: redirect, ticket, 3-D Secure. `Payment::$nextAction` says where. */
    case RequiresAction = 'requires_action';
    /** The provider is still deciding. */
    case Processing = 'processing';
    /** The buyer consented; the merchant must call capture() (PayPal). */
    case Approved = 'approved';
    /** Funds held; capture() or cancel() (manual capture). */
    case Authorized = 'authorized';
    /** Money taken. */
    case Captured = 'captured';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
    case Canceled = 'canceled';
    case Failed = 'failed';
    case Disputed = 'disputed';
    /** A provider state this library does not know. Treat as "do not fulfill". */
    case Unknown = 'unknown';

    /** Money was captured at some point. */
    public function isPaid(): bool
    {
        return in_array($this, [self::Captured, self::PartiallyRefunded, self::Refunded, self::Disputed], true);
    }

    /** Nothing will change without the merchant or a dispute. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Captured, self::PartiallyRefunded, self::Refunded, self::Canceled, self::Failed], true);
    }

    /** The merchant has to act (capture) for money to move. */
    public function awaitsMerchant(): bool
    {
        return $this === self::Approved || $this === self::Authorized;
    }
}
