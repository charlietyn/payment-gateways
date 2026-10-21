<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways;

/**
 * What the buyer must do for the payment to move on.
 */
final readonly class NextAction
{
    public const REDIRECT = 'redirect';

    /**
     * @param  bool  $returnsToMerchant  Whether the provider page sends the buyer back to
     *                                   your returnUrl. False for Mercado Pago tickets and the
     *                                   Plaza / Vector emulator pages: open it in a new tab and
     *                                   poll your own status page.
     */
    public function __construct(
        public string $type,
        public string $url,
        public bool $returnsToMerchant,
    ) {}

    public static function redirect(string $url, bool $returnsToMerchant): self
    {
        return new self(self::REDIRECT, $url, $returnsToMerchant);
    }
}
