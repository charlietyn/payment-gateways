<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Tests\Orchestration;

use PHPUnit\Framework\TestCase;
use Ronu\PaymentGateways\Contracts\{Gateway, DisputeLookup};
use Ronu\PaymentGateways\{Dispute, Money};
use Ronu\PaymentGateways\Orchestration\{Account, GatewayDisputeEvidenceReader, PaymentError};

final class GatewayDisputeEvidenceReaderTest extends TestCase
{
    public function test_known_dispute_uses_historical_merchant_and_never_writes(): void
    {
        $gateway = $this->createMockForIntersectionOfInterfaces([Gateway::class, DisputeLookup::class]);
        $gateway->method('name')->willReturn('mercadopago');
        $gateway->expects(self::never())->method('createPayment');
        $gateway->expects(self::never())->method('refund');
        $gateway->expects(self::once())->method('getDispute')->with('ORD1', '123', '456')->willReturn(new Dispute('123', 'ORD1', new Money(1000, 'ARS'), 'opened'));
        $result = (new GatewayDisputeEvidenceReader())->read(new Account('mp', '456', 'emulator', 'ARS', 1, $gateway, []), 'ORD1', '123');
        self::assertSame('123', $result->id);
        self::assertSame('opened', $result->state);
        self::assertSame(1000, $result->amount->minor);
    }

    public function test_unknown_case_stays_unresolved(): void
    {
        $gateway = $this->createMockForIntersectionOfInterfaces([Gateway::class, DisputeLookup::class]);
        $gateway->method('name')->willReturn('mercadopago');
        $gateway->method('getDispute')->willReturn(null);
        self::assertNull((new GatewayDisputeEvidenceReader())->read(new Account('mp', '456', 'emulator', 'ARS', 1, $gateway, []), 'ORD1', '123'));
    }

    public function test_unsupported_reader_fails_explicitly(): void
    {
        $gateway = $this->createMock(Gateway::class);
        $gateway->method('name')->willReturn('mercadopago');
        $this->expectException(PaymentError::class);
        (new GatewayDisputeEvidenceReader())->read(new Account('mp', '456', 'emulator', 'ARS', 1, $gateway, []), 'ORD1', '123');
    }
}
