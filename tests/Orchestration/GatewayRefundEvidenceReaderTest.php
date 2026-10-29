<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Tests\Orchestration;

use PHPUnit\Framework\TestCase;
use Ronu\PaymentGateways\Contracts\{Gateway, RefundLookup};
use Ronu\PaymentGateways\{Money, Refund};
use Ronu\PaymentGateways\Orchestration\{Account, GatewayRefundEvidenceReader, PaymentError};

final class GatewayRefundEvidenceReaderTest extends TestCase
{
    public function test_missing_identity_remains_unresolved_without_any_provider_io(): void
    {
        $gateway = $this->createMock(Gateway::class);
        $gateway->method('name')->willReturn('mercadopago');
        $gateway->expects(self::never())->method('getPayment');
        $gateway->expects(self::never())->method('refund');
        $account = new Account('mp', 'merchant', 'emulator', 'ARS', 1, $gateway, []);
        self::assertNull((new GatewayRefundEvidenceReader())->read($account, 'ORD1', null, 'original-remote-key'));
    }

    public function test_lookup_delegates_only_the_known_individual_identity(): void
    {
        $gateway = $this->createMockForIntersectionOfInterfaces([Gateway::class, RefundLookup::class]);
        $gateway->method('name')->willReturn('mercadopago');
        $expected = new Refund('REF1', new Money(1000, 'ARS'), 'processed', 'ORD1');
        $gateway->expects(self::once())->method('getRefund')->with('ORD1', 'REF1')->willReturn($expected);
        $gateway->expects(self::never())->method('refund');
        $account = new Account('mp', 'merchant', 'emulator', 'ARS', 1, $gateway, []);
        self::assertSame($expected, (new GatewayRefundEvidenceReader())->read($account, 'ORD1', 'REF1', 'original-remote-key'));
    }

    public function test_unsupported_provider_is_explicit(): void
    {
        $gateway = $this->createMock(Gateway::class);
        $gateway->method('name')->willReturn('mercadopago');
        $account = new Account('mp', 'merchant', 'emulator', 'ARS', 1, $gateway, []);
        $this->expectException(PaymentError::class);
        (new GatewayRefundEvidenceReader())->read($account, 'ORD1', 'REF1', 'original-remote-key');
    }
}
