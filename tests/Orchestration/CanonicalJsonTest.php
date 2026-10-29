<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Tests\Orchestration;

use PHPUnit\Framework\TestCase;
use Ronu\PaymentGateways\Orchestration\{CanonicalJson, PaymentError};
use Ronu\PaymentGateways\Money;

final class CanonicalJsonTest extends TestCase
{
    public function test_equivalent_objects_and_money_are_exact(): void
    {
        self::assertSame(CanonicalJson::encode(['b' => 2,'a' => ['y' => 1,'x' => 2]]), CanonicalJson::encode(['a' => ['x' => 2,'y' => 1],'b' => 2]));
        self::assertNotSame(CanonicalJson::encode([1,2]), CanonicalJson::encode([2,1]));
        self::assertSame('92233720368547758.07', Money::fromDecimal('92233720368547758.07', 'ARS')->toDecimal());
        self::assertSame('123', Money::fromDecimal('123', 'JPY')->toDecimal());
    }
    public function test_float_payload_is_rejected(): void
    {
        $this->expectException(PaymentError::class);
        CanonicalJson::encode(['amount' => 1.23]);
    }
    public function test_money_overflow_is_rejected_before_integer_conversion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::fromDecimal('92233720368547758.08', 'ARS');
    }
}
