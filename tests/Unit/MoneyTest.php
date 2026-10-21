<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ronu\PaymentGateways\Money;

final class MoneyTest extends TestCase
{
    public function test_converts_decimals_with_the_currency_exponent(): void
    {
        $this->assertSame(18_000_050, Money::fromDecimal('180000.5', 'ars')->minor);
        $this->assertSame('180000.50', Money::of(18_000_050, 'ARS')->toDecimal());
        $this->assertSame(1500, Money::fromDecimal('1500', 'JPY')->minor);
        $this->assertSame('1500', Money::of(1500, 'JPY')->toDecimal());
        $this->assertSame('0.07', Money::of(7, 'USD')->toDecimal());
        $this->assertSame(1000, Money::fromDecimal('10.00', 'USD')->minor);
    }

    public function test_refuses_more_precision_than_the_currency_has(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::fromDecimal('10.5', 'JPY');
    }

    public function test_refuses_garbage_negative_and_bad_currency(): void
    {
        foreach ([fn () => Money::fromDecimal('1,5', 'USD'), fn () => Money::of(-1, 'USD'), fn () => Money::of(1, 'US')] as $bad) {
            try {
                $bad();
                $this->fail('expected a rejection');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_equality_includes_the_currency(): void
    {
        $this->assertTrue(Money::of(100, 'usd')->equals(Money::of(100, 'USD')));
        $this->assertFalse(Money::of(100, 'USD')->equals(Money::of(100, 'EUR')));
    }
}
