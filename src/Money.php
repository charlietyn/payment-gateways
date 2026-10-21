<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways;

use InvalidArgumentException;

/**
 * An amount in the currency's minor unit. Never a float.
 *
 * Providers disagree on the wire — Stripe sends integers in minor units, Mercado
 * Pago and PayPal send decimal strings — so the conversion lives here, once,
 * with the currency's exponent.
 */
final readonly class Money
{
    /** ISO-4217 currencies without a minor unit that these providers handle. */
    private const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    public string $currency;

    public function __construct(public int $minor, string $currency)
    {
        $currency = strtoupper($currency);
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException("Invalid currency code: {$currency}");
        }
        if ($minor < 0) {
            throw new InvalidArgumentException('Money cannot be negative.');
        }
        $this->currency = $currency;
    }

    public static function of(int $minor, string $currency): self
    {
        return new self($minor, $currency);
    }

    /** "180000.50" ARS → 18000050. Rejects more decimals than the currency has. */
    public static function fromDecimal(string|int $amount, string $currency): self
    {
        $exponent = self::exponentOf($currency);
        $string = trim((string) $amount);

        if (! preg_match('/^\d+(?:\.(\d+))?$/', $string, $match)) {
            throw new InvalidArgumentException("Invalid decimal amount: {$string}");
        }

        $fraction = rtrim($match[1] ?? '', '0');
        if (strlen($fraction) > $exponent) {
            throw new InvalidArgumentException("{$string} has more decimals than {$currency} allows.");
        }

        [$units] = explode('.', $string);
        $minor = (int) $units * (10 ** $exponent) + (int) str_pad($fraction, $exponent, '0');

        return new self($minor, $currency);
    }

    public static function exponentOf(string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? 0 : 2;
    }

    /** 18000050 ARS → "180000.50". */
    public function toDecimal(): string
    {
        $exponent = self::exponentOf($this->currency);
        if ($exponent === 0) {
            return (string) $this->minor;
        }

        $divisor = 10 ** $exponent;

        return sprintf('%d.%0' . $exponent . 'd', intdiv($this->minor, $divisor), $this->minor % $divisor);
    }

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor && $this->currency === $other->currency;
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }
}
