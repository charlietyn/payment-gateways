<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways;

use InvalidArgumentException;

/**
 * How the buyer pays, when your page already collected it (embedded checkout).
 * The card number never reaches your server: only the provider's token does.
 *
 *     PaymentMethod::card($brickToken, 'visa', installments: 3);  // Mercado Pago Card Payment Brick
 *     PaymentMethod::card('pm_1Q…');                              // Stripe Elements / Payment Element
 *     PaymentMethod::ticket('pagofacil');                         // Mercado Pago cash voucher
 */
final readonly class PaymentMethod
{
    public const CARD = 'card';
    public const TICKET = 'ticket';

    /**
     * @param  string  $type  card | ticket | a provider type (e.g. Mercado Pago `bank_transfer`).
     * @param  string|null  $token  The provider token (Brick card token, Stripe `pm_…`).
     * @param  string|null  $id  The provider method id: card brand for Mercado Pago (`visa`, `master`), ticket id (`pagofacil`).
     */
    public function __construct(
        public string $type,
        public ?string $token = null,
        public ?string $id = null,
        public int $installments = 1,
        public bool $debit = false,
    ) {
        if ($type === '') {
            throw new InvalidArgumentException('A payment method needs a type.');
        }
        if ($installments < 1) {
            throw new InvalidArgumentException('Installments start at 1.');
        }
    }

    /** @param  string|null  $brand  Mercado Pago needs it (`payment_method_id` from the Brick); Stripe does not. */
    public static function card(string $token, ?string $brand = null, int $installments = 1, bool $debit = false): self
    {
        if ($token === '') {
            throw new InvalidArgumentException('A card payment needs the provider token.');
        }

        return new self(self::CARD, $token, $brand, $installments, $debit);
    }

    public static function ticket(string $id): self
    {
        return new self(self::TICKET, null, $id);
    }
}
