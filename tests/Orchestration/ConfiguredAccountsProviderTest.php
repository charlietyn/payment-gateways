<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Tests\Orchestration;

use PHPUnit\Framework\TestCase;
use Ronu\PaymentGateways\CreatePayment;
use Ronu\PaymentGateways\Money;
use Ronu\PaymentGateways\Orchestration\{Account, PaymentError, ProviderView};
use Ronu\PaymentGateways\Orchestration\Laravel\ConfiguredAccounts;
use Ronu\PaymentGateways\PaymentStatus;
use Ronu\PaymentGateways\Services;
use Ronu\PaymentGateways\Testing\FakeMercadoPago;

/**
 * Accounts against the real Mercado Pago (`environment` sandbox or live). Every request lands in
 * FakeMercadoPago: nothing here reaches the network, and no token is a real credential.
 */
final class ConfiguredAccountsProviderTest extends TestCase
{
    private FakeMercadoPago $fake;

    /** @param array<string, mixed> $overrides */
    private function accounts(array $overrides = [], string $environment = 'live'): ConfiguredAccounts
    {
        $this->fake = new FakeMercadoPago();
        $account = array_replace([
            'driver' => 'mercadopago',
            'environment' => $environment,
            'api' => 'checkout_pro',
            'currency' => 'ARS',
            'merchant_id' => '123456789',
            'configuration_version' => 1,
            'access_token' => 'APP_USR-0000000000000000-000000-fake-not-a-credential',
            'webhook_secret' => 'whsec-live-fake',
        ], $overrides);
        return new ConfiguredAccounts(
            ['default_account' => 'mp-ar', 'accept_new' => true, 'accounts' => ['mp-ar' => $account]],
            new Services($this->fake->client(), sleep: static fn () => null),
        );
    }

    private function code(callable $call): string
    {
        try {
            $call();
        } catch (PaymentError $error) {
            return $error->getMessage();
        }
        self::fail('Expected a PaymentError');
    }

    public function test_a_live_account_targets_mercado_pago_checkout_pro(): void
    {
        $account = $this->accounts()->account('mp-ar');
        self::assertSame(['live', 'checkout_pro', 'mercadopago', 'ARS'], [$account->environment, $account->apiFamily, $account->gateway->name(), $account->currency]);
        self::assertSame(['https://www.mercadopago.com.ar'], $account->actionOrigins);

        $payment = ProviderView::create($account, new CreatePayment(Money::fromDecimal('70000.00', 'ARS'), 'ref-live-1', 'idem-live-1', description: 'El Paraíso'));
        self::assertSame(PaymentStatus::RequiresAction, $payment->status);

        $request = $this->fake->requests[0];
        self::assertSame('https://api.mercadopago.com/checkout/preferences', (string) $request->getUri());
        self::assertSame('Bearer APP_USR-0000000000000000-000000-fake-not-a-credential', $request->getHeaderLine('Authorization'));
        self::assertSame('idem-live-1', $request->getHeaderLine('X-Idempotency-Key'));
        self::assertSame('', $request->getHeaderLine('X-Emulator-Scenario'));
        $body = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('ref-live-1', $body['external_reference']);
        self::assertSame(70000.0, (float) $body['items'][0]['unit_price']);
        self::assertSame('ARS', $body['items'][0]['currency_id']);
    }

    public function test_emulator_urls_left_in_the_configuration_never_redirect_a_real_account(): void
    {
        $account = $this->accounts(['api_url' => 'http://localhost:8080', 'checkout_url' => 'http://localhost:3005'])->account('mp-ar');
        ProviderView::create($account, new CreatePayment(Money::fromDecimal('10.00', 'ARS'), 'ref-2', 'idem-2'));
        self::assertSame('api.mercadopago.com', $this->fake->requests[0]->getUri()->getHost());
        self::assertSame(['https://www.mercadopago.com.ar'], $account->actionOrigins);
    }

    public function test_reads_and_payment_go_through_the_preference(): void
    {
        $account = $this->accounts()->account('mp-ar');
        $amount = Money::fromDecimal('70000.00', 'ARS');
        $preference = ProviderView::create($account, new CreatePayment($amount, 'ref-3', 'idem-3'))->id;
        $this->fake->pay($preference);
        $paid = ProviderView::payment($account, $preference, $amount, 'ref-3');
        self::assertSame([$preference, PaymentStatus::Captured], [$paid?->id, $paid?->status]);
        self::assertTrue($paid->matches('ref-3', $amount));
        foreach ($this->fake->requests as $request) {
            self::assertSame('api.mercadopago.com', $request->getUri()->getHost());
        }
    }

    public function test_live_refuses_test_credentials(): void
    {
        self::assertSame('live_requires_production_credentials', $this->code(fn () => $this->accounts(['access_token' => 'TEST-1234'])->account('mp-ar')));
    }

    public function test_sandbox_accepts_test_credentials_and_the_sandbox_checkout_origin(): void
    {
        $account = $this->accounts(['access_token' => 'TEST-1234'], 'sandbox')->account('mp-ar');
        self::assertSame('sandbox', $account->environment);
        self::assertContains('https://sandbox.mercadopago.com.ar', $account->actionOrigins);
    }

    public function test_only_mercado_pago_checkout_pro_leaves_the_emulator(): void
    {
        self::assertSame('unsupported_live_api', $this->code(fn () => $this->accounts(['api' => 'orders'])->account('mp-ar')));
        self::assertSame('unsupported_account', $this->code(fn () => $this->accounts(['driver' => 'stripe', 'currency' => 'USD'])->account('mp-ar')));
        self::assertSame('unsupported_account', $this->code(fn () => $this->accounts(['currency' => 'USD'])->account('mp-ar')));
        self::assertSame('unsupported_account', $this->code(fn () => $this->accounts([], 'production')->account('mp-ar')));
    }

    public function test_a_missing_credential_makes_the_account_not_ready(): void
    {
        foreach (['access_token', 'webhook_secret', 'merchant_id'] as $key) {
            self::assertSame('account_not_ready', $this->code(fn () => $this->accounts([$key => ''])->account('mp-ar')), $key);
        }
    }

    public function test_the_environment_is_part_of_the_identity(): void
    {
        $live = $this->accounts()->account('mp-ar');
        $sandbox = $this->accounts([], 'sandbox')->account('mp-ar');
        self::assertNotSame($live->identity(), $sandbox->identity());
        self::assertSame(hash('sha256', json_encode(['mp-ar', 'mercadopago', 'checkout_pro', '123456789', 'live', 'ARS'], JSON_THROW_ON_ERROR)), $live->identity());
        // History recorded on the sandbox account is never adopted by the live one.
        self::assertSame('account_identity_changed', $this->code(fn () => $this->accounts()->historical('mp-ar', $sandbox->identity())));
    }

    public function test_webhooks_verify_with_the_mercado_pago_signature_and_rotation(): void
    {
        $until = time() + 3600;
        $account = $this->accounts(['webhook_secret_previous' => 'whsec-old', 'webhook_secret_previous_until' => gmdate('c', $until)])->account('mp-ar');
        foreach (['whsec-live-fake', 'whsec-old'] as $secret) {
            $ts = time();
            $body = json_encode(['id' => 'evt-1', 'type' => 'payment', 'action' => 'payment.updated', 'data' => ['id' => '1234567890']], JSON_THROW_ON_ERROR);
            $headers = ['x-signature' => ['ts='.$ts.',v1='.hash_hmac('sha256', "id:1234567890;request-id:req-9;ts:{$ts};", $secret)], 'x-request-id' => ['req-9']];
            $accepted = false;
            foreach ($account->webhookVerifiers(time()) as $verifier) {
                try {
                    $event = $verifier->parseWebhook($headers, $body);
                    self::assertSame('1234567890', $event->paymentId);
                    $accepted = true;
                    break;
                } catch (\Ronu\PaymentGateways\Exceptions\InvalidWebhookSignature) {
                }
            }
            self::assertTrue($accepted, $secret);
        }
    }

    public function test_statement_descriptor_is_validated_and_sent(): void
    {
        self::assertSame('invalid_statement_descriptor', $this->code(fn () => $this->accounts(['statement_descriptor' => 'Eros <script>'])->account('mp-ar')));
        $account = $this->accounts(['statement_descriptor' => 'EROS EXPERIENCE'])->account('mp-ar');
        ProviderView::create($account, new CreatePayment(Money::fromDecimal('10.00', 'ARS'), 'ref-4', 'idem-4'));
        $body = json_decode((string) $this->fake->requests[0]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('EROS EXPERIENCE', $body['statement_descriptor']);
    }

    public function test_an_account_without_environment_stays_on_the_emulator_rules(): void
    {
        $accounts = $this->accounts(['environment' => null, 'api' => 'orders', 'api_url' => 'https://api.mercadopago.com', 'checkout_url' => 'https://www.mercadopago.com.ar']);
        // A real URL cannot silently turn an emulator account into live transport.
        self::assertSame('unsafe_emulator_endpoint', $this->code(fn () => $accounts->account('mp-ar')));
    }

    public function test_account_accepts_only_known_environments(): void
    {
        $gateway = $this->accounts()->account('mp-ar')->gateway;
        self::assertSame('unsupported_account', $this->code(fn () => new Account('mp-ar', 'm', 'production', 'ARS', 1, $gateway, [], api: 'checkout_pro')));
    }
}
