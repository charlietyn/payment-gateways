<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Tests\Orchestration;

use PHPUnit\Framework\TestCase;
use Ronu\PaymentGateways\Orchestration\{Actor, PaymentError};
use Ronu\PaymentGateways\Orchestration\Laravel\ConfiguredAccounts;

/**
 * The resolver generated applications actually bind. A historical operation is resolved by the
 * alias AND merchant identity it was recorded with, never by whatever the configuration calls
 * the default today. Building an account performs no I/O, so no database or emulator is needed.
 */
final class ConfiguredAccountsTest extends TestCase
{
    private function configuration(): array
    {
        $account = static fn (string $merchant, string $token) => [
            'merchant_id' => $merchant,
            'configuration_version' => 1,
            'access_token' => $token,
            'webhook_secret' => 'secret-'.$token,
            'api_url' => 'http://127.0.0.1:55454',
            'checkout_url' => 'http://127.0.0.1:55455',
        ];
        return ['default_account' => 'a', 'accept_new' => true, 'accounts' => ['a' => $account('merchant-a', 'token-a'), 'b' => $account('merchant-b', 'token-b')]];
    }

    private function actor(array $accounts = ['a', 'b']): Actor
    {
        return new Actor('invoices', 'tenant-a', 'buyer-a', $accounts, ['create', 'read']);
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

    public function test_credential_and_version_rotation_keeps_the_historical_identity(): void
    {
        $config = $this->configuration();
        $identity = (new ConfiguredAccounts($config))->account('a')->identity();
        $config['accounts']['a']['access_token'] = 'rotated-token';
        $config['accounts']['a']['webhook_secret'] = 'rotated-secret';
        $config['accounts']['a']['configuration_version'] = 7;
        $account = (new ConfiguredAccounts($config))->historical('a', $identity);
        self::assertSame('merchant-a', $account->merchant);
        self::assertSame(7, $account->configurationVersion);
    }

    public function test_changing_the_default_moves_new_requests_only(): void
    {
        $config = $this->configuration();
        $identity = (new ConfiguredAccounts($config))->account('a')->identity();
        $config['default_account'] = 'b';
        $accounts = new ConfiguredAccounts($config);
        self::assertSame('b', $accounts->forNew($this->actor(), null)->alias);
        self::assertSame('merchant-a', $accounts->historical('a', $identity)->merchant);
        // A default outside the actor's allowlist is never a fallback.
        self::assertSame('forbidden', $this->code(fn () => $accounts->forNew($this->actor(['a']), null)));
    }

    public function test_reassigning_an_alias_to_another_merchant_fails_closed_for_history(): void
    {
        $config = $this->configuration();
        $identity = (new ConfiguredAccounts($config))->account('a')->identity();
        $config['accounts']['a']['merchant_id'] = 'merchant-z';
        $accounts = new ConfiguredAccounts($config);
        self::assertSame('account_identity_changed', $this->code(fn () => $accounts->historical('a', $identity)));
        // Moving the old merchant under a NEW alias does not adopt the history either: identity binds the alias.
        $config['accounts']['c'] = $this->configuration()['accounts']['a'];
        self::assertSame('account_identity_changed', $this->code(fn () => (new ConfiguredAccounts($config))->historical('c', $identity)));
    }

    public function test_retiring_an_alias_fails_closed_for_history_and_new_requests(): void
    {
        $config = $this->configuration();
        $identity = (new ConfiguredAccounts($config))->account('a')->identity();
        unset($config['accounts']['a']);
        $accounts = new ConfiguredAccounts($config);
        self::assertSame('unknown_account', $this->code(fn () => $accounts->historical('a', $identity)));
        self::assertSame('unknown_account', $this->code(fn () => $accounts->forNew($this->actor(), 'a')));
    }

    public function test_the_account_never_overrides_the_emulator_application_scenario(): void
    {
        $gateway = (new ConfiguredAccounts($this->configuration()))->account('a')->gateway;
        $headers = null;
        foreach ((new \ReflectionObject($gateway))->getProperties() as $property) {
            $value = $property->getValue($gateway);
            if ($value instanceof \Ronu\PaymentGateways\Config\GatewayConfig) {
                $headers = $value->emulatorHeaders();
            }
        }
        self::assertSame([], $headers);
    }

    public function test_an_account_missing_its_credential_is_not_ready_rather_than_silently_skipped(): void
    {
        $config = $this->configuration();
        $config['accounts']['a']['access_token'] = '';
        self::assertSame('account_not_ready', $this->code(fn () => (new ConfiguredAccounts($config))->account('a')));
    }

    /** @return array{0: array<string, list<string>>, 1: string} headers and body signed with $secret */
    private function signed(string $secret): array
    {
        $ts = time();
        $body = json_encode(['id' => 'evt-'.bin2hex(random_bytes(4)), 'type' => 'order', 'action' => 'order.updated', 'data' => ['id' => 'ORD1']], JSON_THROW_ON_ERROR);
        $hmac = hash_hmac('sha256', "id:ORD1;request-id:req-1;ts:{$ts};", $secret);
        return [['x-signature' => ["ts={$ts},v1={$hmac}"], 'x-request-id' => ['req-1']], $body];
    }

    /** @return string|null the gateway reason of the first verifier rejection, null when one accepts */
    private function verify(\Ronu\PaymentGateways\Orchestration\Account $account, string $secret, int $now): ?string
    {
        [$headers, $body] = $this->signed($secret);
        $last = null;
        foreach ($account->webhookVerifiers($now) as $verifier) {
            try {
                $verifier->parseWebhook($headers, $body);
                return null;
            } catch (\Ronu\PaymentGateways\Exceptions\InvalidWebhookSignature $error) {
                $last = $error->reason;
            }
        }
        return $last;
    }

    public function test_without_rotation_only_the_current_secret_verifies(): void
    {
        $account = (new ConfiguredAccounts($this->configuration()))->account('a');
        self::assertCount(1, $account->webhookVerifiers(time()));
        self::assertNull($this->verify($account, 'secret-token-a', time()));
        self::assertSame('signature_mismatch', $this->verify($account, 'some-old-secret', time()));
    }

    public function test_previous_secret_verifies_only_inside_its_finite_window(): void
    {
        $config = $this->configuration();
        $until = time() + 3600;
        $config['accounts']['a'] += ['webhook_secret_previous' => 'old-secret', 'webhook_secret_previous_until' => gmdate('c', $until)];
        $account = (new ConfiguredAccounts($config))->account('a');
        // Inside the window both secrets verify; the current one is always first.
        self::assertCount(2, $account->webhookVerifiers($until - 1));
        self::assertNull($this->verify($account, 'secret-token-a', $until - 1));
        self::assertNull($this->verify($account, 'old-secret', $until - 1));
        // At and after the end only the current secret remains.
        self::assertCount(1, $account->webhookVerifiers($until));
        self::assertSame('signature_mismatch', $this->verify($account, 'old-secret', $until));
        self::assertNull($this->verify($account, 'secret-token-a', $until + 86400));
        // A third secret never verifies, in or out of the window.
        self::assertSame('signature_mismatch', $this->verify($account, 'unrelated', $until - 1));
        // Rotation does not change the account identity.
        self::assertSame((new ConfiguredAccounts($this->configuration()))->account('a')->identity(), $account->identity());
    }

    public function test_an_unbounded_or_ambiguous_rotation_makes_the_account_not_ready(): void
    {
        foreach ([
            'missing end' => ['webhook_secret_previous' => 'old-secret'],
            'unparseable end' => ['webhook_secret_previous' => 'old-secret', 'webhook_secret_previous_until' => 'soon'],
            'window over 7 days' => ['webhook_secret_previous' => 'old-secret', 'webhook_secret_previous_until' => gmdate('c', time() + ConfiguredAccounts::MAX_ROTATION_SECONDS + 3600)],
            'previous equals current' => ['webhook_secret_previous' => 'secret-token-a', 'webhook_secret_previous_until' => gmdate('c', time() + 60)],
        ] as $case => $rotation) {
            $config = $this->configuration();
            $config['accounts']['a'] += $rotation;
            self::assertSame('invalid_webhook_rotation', $this->code(fn () => (new ConfiguredAccounts($config))->account('a')), $case);
        }
        // An expired window is not an error: the old secret is simply no longer accepted.
        $config = $this->configuration();
        $config['accounts']['a'] += ['webhook_secret_previous' => 'old-secret', 'webhook_secret_previous_until' => gmdate('c', time() - 60)];
        self::assertCount(1, (new ConfiguredAccounts($config))->account('a')->webhookVerifiers(time()));
    }
}
