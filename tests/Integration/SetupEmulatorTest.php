<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Tests\Integration;

use Ronu\PaymentGateways\Orchestration\Laravel\Setup\EmulatorProvisioner;

/** Se ejecuta con run-gateways.mjs bajo marcador, rol restringido y bloqueo. */
final class SetupEmulatorTest extends EmulatorTestCase
{
    public function test_provisioning_three_providers_is_idempotent_and_updates_webhooks(): void
    {
        $provisioner = new EmulatorProvisioner(self::$control);
        foreach (['mercadopago', 'stripe', 'paypal'] as $driver) {
            $name = 'payment-setup-'.bin2hex(random_bytes(8)).'-'.$driver;
            $url = 'http://127.0.0.1:'.self::$sinkPort.'/client/payments/webhooks/'.$driver;
            $created = $provisioner->provision($name, $driver, $url, [], false, static function (): void {
            });
            self::assertSame('created', $created['status']);
            self::assertNotSame('', $created['values']['access_token']);
            if ($driver === 'paypal') {
                self::assertNotSame('', $created['values']['client_id']);
            }
            $again = $provisioner->provision($name, $driver, $url, $created['values'], false, static function (): void {
            });
            self::assertSame('unchanged', $again['status']);
            self::assertSame($created['application'], $again['application']);
            self::assertSame($created['values'], $again['values']);
            $changed = $provisioner->provision($name, $driver, $url.'-updated', $created['values'], false, static function (): void {
            });
            self::assertSame('updated', $changed['status']);
            self::assertSame($created['values'], $changed['values']);
            // Solo se eliminan los recursos creados por esta prueba, después de validarlos.
            self::$control->delete('/applications/'.$created['application']);
        }
    }
}
