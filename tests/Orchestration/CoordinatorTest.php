<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Tests\Orchestration;

use PDO;
use PHPUnit\Framework\TestCase;
use Ronu\PaymentGateways\Contracts\Gateway;
use Ronu\PaymentGateways\{CreatePayment, Money, NextAction, Payment, PaymentStatus};
use Ronu\PaymentGateways\Orchestration\{Account, Actor, CanonicalJson, Coordinator, PaymentError, PostgresStore, Quote, RecoveryWorker, RefundCoordinator};
use Ronu\PaymentGateways\Orchestration\Contracts\RefundEvidenceReader;
use Ronu\PaymentGateways\Orchestration\ExternalRefundCoordinator;
use Ronu\PaymentGateways\Orchestration\CompensationCoordinator;
use Ronu\PaymentGateways\Orchestration\Contracts\FinancialEventConsumer;
use Ronu\PaymentGateways\Orchestration\{DisputeCoordinator, DisputeEvidence};
use Ronu\PaymentGateways\Orchestration\Contracts\DisputeEvidenceReader;
use Ronu\PaymentGateways\{Refund, Capability};
use Ronu\PaymentGateways\Orchestration\Contracts\{AccountResolver, SubjectResolver, Fulfillment};

final class FixtureAccounts implements AccountResolver
{
    public string $default = 'a';
    public array $accounts = [];
    public function forNew(Actor $actor, ?string $alias): Account
    {
        $alias ??= $this->default;
        $actor->require('create', $alias);
        return $this->accounts[$alias] ?? throw new PaymentError('not_ready', 503);
    }
    public function historical(string $alias, string $identity): Account
    {
        $account = $this->accounts[$alias] ?? throw new PaymentError('not_ready', 503);
        if ($account->identity() !== $identity) {
            throw new PaymentError('account_identity_changed', 503);
        }
        return $account;
    }
}

final class FixtureSubjects implements SubjectResolver
{
    public array $quotes = [];
    public function resolve(Actor $actor, string $subject): Quote
    {
        return $this->quotes[$subject] ?? throw new PaymentError('not_found', 404);
    }
}

final class CoordinatorTest extends TestCase
{
    private PDO $db;
    private string $schema;
    private PostgresStore $store;
    private Coordinator $coordinator;
    private Actor $actor;
    private FixtureAccounts $accounts;
    private FixtureSubjects $subjects;
    private Gateway $gateway;
    private array $mapping = [];
    private int $posts = 0;
    private array $ioChecks = [];
    private \Closure $remote;

    protected function setUp(): void
    {
        // Fail closed, before connecting or DDL. The outer runner also holds a session advisory lock.
        if (getenv('APP_ENV') !== 'testing' || getenv('PGHOST') !== '127.0.0.1' || getenv('PGDATABASE') !== 'payment_feature_invoices_testing' || getenv('PGUSER') !== 'payment_feature_owner' || getenv('PAYMENT_TEST_LOCK_HELD') !== '1' || !ctype_digit((string) getenv('PGPORT')) || (int) getenv('PGPORT') < 1024 || getenv('PGPORT') === '5432') {
            throw new \RuntimeException('Unsafe test environment.');
        }
        $this->db = new PDO('pgsql:host=127.0.0.1;port='.getenv('PGPORT').';dbname=payment_feature_invoices_testing', 'payment_feature_owner', (string) getenv('PGPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $role = $this->db->query('SELECT rolsuper,rolcreatedb,rolcreaterole FROM pg_roles WHERE rolname=current_user')->fetch(PDO::FETCH_ASSOC);
        self::assertFalse((bool) $role['rolsuper']);
        self::assertFalse((bool) $role['rolcreatedb']);
        self::assertFalse((bool) $role['rolcreaterole']);
        $this->schema = 'payment_test_'.bin2hex(random_bytes(8));
        $this->db->exec('CREATE SCHEMA '.$this->schema);
        $this->db->exec('SET search_path TO '.$this->schema);
        $this->db->exec(file_get_contents(__DIR__.'/schema.sql'));
        $mapping = [];
        foreach (['intents', 'operations', 'transactions', 'outbox', 'refunds', 'inbox', 'reconciliation', 'replays'] as $key) {
            $mapping[$key] = ['schema' => $this->schema, 'table' => $key];
        }
        $this->mapping = $mapping;
        $this->store = new PostgresStore($this->db, $mapping);
        $this->actor = new Actor('invoices', 'tenant-a', 'buyer-a', ['a'], ['create', 'read']);
        $this->subjects = new FixtureSubjects();
        $this->subjects->quotes['invoice:uuid-example'] = new Quote('invoice:uuid-example', 'v1', $this->actor->scope(), Money::fromDecimal('120.00', 'ARS'), 2000);
        $this->gateway = $this->createMock(Gateway::class);
        $this->gateway->method('name')->willReturn('mercadopago');
        $this->remote = fn (CreatePayment $request) => $this->payment($request->reference, PaymentStatus::RequiresAction);
        $this->gateway->method('createPayment')->willReturnCallback(function (CreatePayment $request): Payment {
            $query = $this->db->prepare("SELECT data->>'state' FROM operations WHERE intent_reference=?");
            $query->execute([$request->reference]);
            $this->ioChecks[] = [$this->db->inTransaction(), $query->fetchColumn(), (bool) preg_match('/^[0-9a-f-]{36}$/D', $request->idempotencyKey)];
            $this->posts++;
            return ($this->remote)($request);
        });
        $this->accounts = new FixtureAccounts();
        $this->accounts->accounts['a'] = $this->account();
        $this->coordinator = new Coordinator($this->store, $this->subjects, $this->accounts, fn () => 1000);
    }

    protected function tearDown(): void
    {
        if (isset($this->schema) && preg_match('/^payment_test_[a-f0-9]{16}$/D', $this->schema)) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->db->exec('DROP SCHEMA '.$this->schema.' CASCADE');
        }
        // Assert outside the transport callback: the coordinator deliberately catches transport failures.
        foreach ($this->ioChecks as $check) {
            self::assertSame([false, 'SENDING', true], $check);
        }
    }

    public function test_manual_compensation_replays_a_lost_product_ack_without_inventing_refund(): void
    {
        $this->remote = fn (CreatePayment $request) => $this->payment($request->reference, PaymentStatus::Captured);
        $payment = $this->start();
        $this->store->finishFulfillment($payment['reference'], 'compensation_required');
        $this->store->finishFulfillment($payment['reference'], 'compensation_requested');
        $product = new class () implements \Ronu\PaymentGateways\Orchestration\Contracts\ManualCompensation {
            public array $receipts = [];
            public bool $loseResponse = true;
            public function acknowledgeCompensation(string $receiptId, string $subject, array $acknowledgement): void
            {
                $this->receipts[$receiptId] = $acknowledgement;
                if ($this->loseResponse) {
                    $this->loseResponse = false;
                    throw new \RuntimeException('Product response lost after durable receipt');
                }
            }
        };
        $coordinator = new CompensationCoordinator($this->store, $product);
        try {
            $coordinator->acknowledge($payment['reference'], 'operator-test', 'review:123', 'ack-1');
            self::fail('Lost response accepted');
        } catch (\RuntimeException $error) {
            self::assertSame('Product response lost after durable receipt', $error->getMessage());
        }
        self::assertSame('pending', $this->store->intent($payment['reference'])['compensation']);
        $first = $coordinator->acknowledge($payment['reference'], 'operator-test', 'review:123', 'ack-1');
        self::assertSame($first, $coordinator->acknowledge($payment['reference'], 'operator-test', 'review:123', 'ack-1'));
        self::assertCount(1, $product->receipts);
        $intent = $this->store->intent($payment['reference']);
        self::assertSame('completed', $intent['compensation']);
        self::assertSame('captured', $intent['state']);
        self::assertSame('0', $intent['refunded_minor']);
        self::assertSame('12000', $intent['captured_minor']);
        self::assertSame(0, (int) $this->db->query("SELECT count(*) FROM transactions WHERE kind='refund'")->fetchColumn());
        self::assertSame(1, $this->posts);
        try {
            $coordinator->acknowledge($payment['reference'], 'operator-test', 'review:changed', 'ack-1');
            self::fail('Changed acknowledgement accepted');
        } catch (PaymentError $error) {
            self::assertSame('compensation_acknowledgement_conflict', $error->reason);
        }
        self::assertCount(1, $product->receipts);
    }

    public function test_manual_compensation_requires_a_pending_product_request_before_calling_product(): void
    {
        $payment = $this->start();
        $product = $this->createMock(\Ronu\PaymentGateways\Orchestration\Contracts\ManualCompensation::class);
        $product->expects(self::never())->method('acknowledgeCompensation');
        $this->expectException(PaymentError::class);
        (new CompensationCoordinator($this->store, $product))->acknowledge($payment['reference'], 'operator', 'review:1', 'ack-1');
    }

    public function test_unknown_refund_requires_individual_evidence_and_an_immutable_review_before_recovery(): void
    {
        $this->remote = fn (CreatePayment $request) => $this->payment($request->reference, PaymentStatus::Captured);
        $payment = $this->start();
        $refund = $this->store->reserveRefund($payment['reference'], 'historical-lost-response', 3000);
        $this->store->claimRefund($refund['reference']);
        $this->store->markRefundUncertain($refund['reference']);
        $evidence = null;
        $reader = $this->createMock(RefundEvidenceReader::class);
        $reader->method('read')->willReturnCallback(function () use (&$evidence) {
            return $evidence;
        });
        $coordinator = new RefundCoordinator($this->store, $this->accounts, $reader, false);
        self::assertFalse($coordinator->recover($refund['reference']));
        self::assertFalse($coordinator->recoverReviewed($refund['reference'], 'REF-one', 'operator-test', 'review:1'));
        self::assertSame('UNKNOWN', $this->store->refundOperation($refund['reference'])['state']);
        self::assertArrayNotHasKey('refund_resolution_audits', $this->store->intent($payment['reference']));
        $evidence = new Refund('REF-one', new Money(3001, 'ARS'), 'approved', 'ORD-test');
        try {
            $coordinator->recoverReviewed($refund['reference'], 'REF-one', 'operator-test', 'review:1');
            self::fail('Incorrect amount accepted');
        } catch (PaymentError $error) {
            self::assertSame('refund_evidence_invalid', $error->reason);
        }
        self::assertSame('UNKNOWN', $this->store->refundOperation($refund['reference'])['state']);
        $evidence = new Refund('REF-one', new Money(3000, 'ARS'), 'approved', 'ORD-test');
        self::assertTrue($coordinator->recoverReviewed($refund['reference'], 'REF-one', 'operator-test', 'review:1'));
        self::assertTrue($coordinator->recoverReviewed($refund['reference'], 'REF-one', 'operator-test', 'review:1'));
        self::assertSame('COMPLETED', $this->store->refundOperation($refund['reference'])['state']);
        $intent = $this->store->intent($payment['reference']);
        self::assertSame('3000', $intent['refunded_minor']);
        self::assertSame('operator-test', $intent['refund_resolution_audits'][$refund['reference']]['decision']['actor']);
        self::assertSame(1, (int) $this->db->query("SELECT count(*) FROM transactions WHERE kind='refund'")->fetchColumn());
        self::assertSame(1, $this->posts);
        try {
            $coordinator->recoverReviewed($refund['reference'], 'REF-one', 'operator-test', 'review:changed');
            self::fail('Review was rewritten');
        } catch (PaymentError $error) {
            self::assertSame('refund_resolution_conflict', $error->reason);
        }
    }

    private function account(string $merchant = 'merchant-a', int $version = 1): Account
    {
        return new Account('a', $merchant, 'emulator', 'ARS', $version, $this->gateway, ['http://127.0.0.1:55455'], true);
    }

    private function payment(string $reference, PaymentStatus $status, ?Money $amount = null): Payment
    {
        return new Payment('mercadopago', 'ORD-test', $status, $amount ?? Money::fromDecimal('120.00', 'ARS'), $reference, 'test', transactionId: $status->isPaid() ? 'transaction-test' : null, capturedAmount: $status->isPaid() ? Money::fromDecimal('120.00', 'ARS') : null);
    }

    private function start(string $key = 'request-1'): array
    {
        return $this->coordinator->checkout($this->actor, 'invoice:uuid-example', 'v1', $key);
    }

    public function test_replay_survives_default_quote_and_credential_changes_without_new_post(): void
    {
        $first = $this->start();
        $this->accounts->default = 'unconfigured';
        $this->subjects->quotes = [];
        $this->accounts->accounts['a'] = $this->account(version: 2);
        self::assertSame($first, $this->start());
        self::assertSame(1, $this->posts);
        self::assertSame('120.00', $first['amount']);
        self::assertStringNotContainsString('remote_key', json_encode($first));
        self::assertStringNotContainsString('merchant', json_encode($first));
    }

    public function test_changed_payload_and_new_key_cannot_charge_the_subject_twice(): void
    {
        $this->start();
        foreach (['same-key-changed-payload', 'new-key-same-subject'] as $case) {
            try {
                $case === 'same-key-changed-payload'
                    ? $this->coordinator->checkout($this->actor, 'invoice:other', 'v1', 'request-1')
                    : $this->start('request-2');
                self::fail('Duplicate commercial intent was accepted.');
            } catch (PaymentError $error) {
                self::assertSame(409, $error->httpStatus);
            }
        }
        self::assertSame(1, $this->posts);
    }

    public function test_timeout_is_durable_unknown_and_process_reconstruction_never_reposts(): void
    {
        $this->remote = function () {
            throw new \RuntimeException('transport may contain a secret');
        };
        $first = $this->start();
        self::assertSame('UNKNOWN', $first['operationState']);
        self::assertSame(202, $first['httpStatus']);
        $this->coordinator = new Coordinator($this->store, $this->subjects, $this->accounts, fn () => 99999999);
        self::assertSame($first, $this->start());
        self::assertSame(1, $this->posts);
        self::assertStringNotContainsString('secret', json_encode($first));
        self::assertFalse($this->coordinator->refresh($first['reference']));
    }

    public function test_account_permission_owner_and_quote_are_checked_before_io(): void
    {
        foreach ([
            new Actor('invoices', 'tenant-b', 'buyer-a', ['a'], ['create']),
            new Actor('invoices', 'tenant-a', 'buyer-a', ['b'], ['create']),
            new Actor('invoices', 'tenant-a', 'buyer-a', ['a'], ['read']),
        ] as $actor) {
            try {
                $this->coordinator->checkout($actor, 'invoice:uuid-example', 'v1', 'request-1');
                self::fail();
            } catch (PaymentError $error) {
                self::assertContains($error->httpStatus, [403, 404]);
            }
        }
        $this->subjects->quotes['invoice:uuid-example'] = new Quote('invoice:uuid-example', 'v1', $this->actor->scope(), Money::fromDecimal('120', 'USD'), 2000);
        try {
            $this->start();
            self::fail();
        } catch (PaymentError $error) {
            self::assertSame(422, $error->httpStatus);
        }
        self::assertSame(0, $this->posts);
    }

    public function test_capture_commits_ledger_and_event_once_without_claiming_delivery(): void
    {
        $this->remote = fn (CreatePayment $request) => $this->payment($request->reference, PaymentStatus::Captured);
        $result = $this->start();
        self::assertSame('captured', $result['financialState']);
        self::assertSame('pending', $result['fulfillmentState']);
        self::assertSame('CONFIRMED', $result['operationState']);
        $intent = $this->store->intent($result['reference']);
        self::assertTrue($this->coordinator->apply($intent, $this->payment($result['reference'], PaymentStatus::Captured), $this->account()));
        self::assertSame(1, (int) $this->db->query('SELECT count(*) FROM transactions')->fetchColumn());
        self::assertSame(1, (int) $this->db->query('SELECT count(*) FROM outbox')->fetchColumn());
    }

    public function test_approved_authorized_and_initial_refund_never_emit_delivery(): void
    {
        foreach ([PaymentStatus::Approved, PaymentStatus::Authorized, PaymentStatus::Refunded] as $status) {
            $subject = 'invoice:'.$status->value;
            $this->subjects->quotes[$subject] = new Quote($subject, 'v1', $this->actor->scope(), Money::fromDecimal('120', 'ARS'), 2000);
            $this->remote = fn (CreatePayment $request) => new Payment('mercadopago', 'ORD-'.$status->value, $status, $request->amount, $request->reference, 'test', transactionId: 'tx-'.$status->value, capturedAmount: $status->isPaid() ? $request->amount : null);
            $this->coordinator->checkout($this->actor, $subject, 'v1', $status->value);
        }
        self::assertSame(0, (int) $this->db->query('SELECT count(*) FROM outbox')->fetchColumn());
    }

    public function test_out_of_order_observation_cannot_replace_money_ids_or_state(): void
    {
        $result = $this->start();
        $older = $this->store->intent($result['reference']);
        self::assertTrue($this->coordinator->apply($older, $this->payment($result['reference'], PaymentStatus::Captured), $this->account()));
        self::assertFalse($this->coordinator->apply($older, $this->payment($result['reference'], PaymentStatus::RequiresAction), $this->account()));
        $current = $this->store->intent($result['reference']);
        self::assertSame('captured', $current['state']);
        self::assertSame('12000', $current['captured_minor']);
        self::assertSame('transaction-test', $current['transaction_id']);
    }

    public function test_failed_outbox_write_rolls_back_ledger_aggregate_and_confirmation(): void
    {
        $this->db->exec("ALTER TABLE outbox ADD CONSTRAINT fail_event CHECK (type <> 'payment.captured')");
        $this->remote = fn (CreatePayment $request) => $this->payment($request->reference, PaymentStatus::Captured);
        $result = $this->start();
        self::assertSame('UNKNOWN', $result['operationState']);
        self::assertSame('created', $result['financialState']);
        self::assertSame(0, (int) $this->db->query('SELECT count(*) FROM transactions')->fetchColumn());
        self::assertSame(0, (int) $this->db->query('SELECT count(*) FROM outbox')->fetchColumn());
    }

    public function test_expired_lease_owner_cannot_ack_a_reclaimed_event(): void
    {
        $this->remote = fn (CreatePayment $request) => $this->payment($request->reference, PaymentStatus::Captured);
        $this->start();
        $first = $this->store->claimEvent();
        self::assertNull($this->store->claimEvent());
        $this->db->exec("UPDATE outbox SET leased_until=clock_timestamp()-interval '1 second'");
        self::assertFalse($this->store->acknowledgeEvent($first['eventId'], $first['leaseToken']));
        $second = $this->store->claimEvent();
        self::assertSame($first['eventId'], $second['eventId']);
        self::assertNotSame($first['leaseToken'], $second['leaseToken']);
        self::assertFalse($this->store->acknowledgeEvent($first['eventId'], $first['leaseToken']));
        self::assertTrue($this->store->acknowledgeEvent($second['eventId'], $second['leaseToken']));
        self::assertNull($this->store->claimEvent());
    }

    public function test_operation_polling_has_no_remote_io_and_hides_other_owners(): void
    {
        $result = $this->start();
        self::assertSame($result, $this->coordinator->operationView($this->actor, $result['operationReference']));
        self::assertSame(1, $this->posts);
        $other = new Actor('invoices', 'tenant-a', 'buyer-b', ['a'], ['read']);
        $this->expectException(PaymentError::class);
        $this->coordinator->operationView($other, $result['operationReference']);
    }

    public function test_merchant_reassignment_and_unsafe_action_fail_closed(): void
    {
        $this->remote = fn (CreatePayment $request) => new Payment('mercadopago', 'ORD-test', PaymentStatus::RequiresAction, $request->amount, $request->reference, 'test', nextAction: NextAction::redirect('https://evil.test/checkout', true));
        self::assertSame('UNKNOWN', $this->start()['operationState']);
        $this->expectException(PaymentError::class);
        $this->accounts->historical('a', $this->account('reassigned')->identity());
    }

    public function test_historical_recovery_keeps_its_recorded_merchant_across_default_change_reassignment_retirement_and_rotation(): void
    {
        $first = $this->start();
        $base = $this->store->intent($first['reference']);
        $operation = $this->store->operation($first['operationReference']);
        $this->accounts->accounts['b'] = new Account('b', 'merchant-b', 'emulator', 'ARS', 1, $this->gateway, ['http://127.0.0.1:55455'], true);
        $sibling = $base;
        $sibling['reference'] = CanonicalJson::uuid();
        $sibling['subject'] = 'invoice:sibling';
        $sibling['account_alias'] = 'b';
        $sibling['account_identity'] = $this->accounts->accounts['b']->identity();
        $sibling['provider_id'] = 'ORD-b';
        $siblingOperation = $operation;
        $siblingOperation['reference'] = CanonicalJson::uuid();
        $siblingOperation['intent_reference'] = $sibling['reference'];
        $siblingOperation['key'] = 'sibling-request';
        $siblingOperation['account_alias'] = 'b';
        $this->store->transaction(function () use ($sibling, $siblingOperation): void {
            $this->store->insertIntent($sibling);
            $this->store->insertOperation($siblingOperation);
        });
        $owners = ['ORD-test' => $base['reference'], 'ORD-b' => $sibling['reference']];
        $captured = fn (string $id) => new Payment('mercadopago', $id, PaymentStatus::Captured, new Money(12000, 'ARS'), $owners[$id], 'captured', transactionId: 'TX-'.$id, capturedAmount: new Money(12000, 'ARS'));
        $reads = [];
        $this->gateway->method('getPayment')->willReturnCallback(function (string $id) use (&$reads, $captured): Payment {
            $reads[] = $id;
            return $captured($id);
        });
        // The merchant the alias is moved to. It knows nothing of this intent; reading it at all is the defect.
        $hostileReads = 0;
        $hostile = $this->createMock(Gateway::class);
        $hostile->method('name')->willReturn('mercadopago');
        $hostile->method('getPayment')->willReturnCallback(function (string $id) use (&$hostileReads, $captured): Payment {
            $hostileReads++;
            return $captured($id);
        });
        $worker = new RecoveryWorker($this->store, $this->coordinator, $this->accounts, $this->createMock(Fulfillment::class));
        // Make deferred work due again; backoff timing is not what this test is about.
        $due = fn () => $this->db->exec('UPDATE reconciliation SET reconcile_due=clock_timestamp()');
        $unchanged = function () use ($base): void {
            $now = $this->store->intent($base['reference']);
            self::assertSame($base['version'], $now['version']);
            self::assertSame($base['state'], $now['state']);
            self::assertSame($base['account_identity'], $now['account_identity']);
        };

        // 1. The default moves to b and alias a is reassigned to another merchant.
        $this->accounts->default = 'b';
        $this->accounts->accounts['a'] = new Account('a', 'merchant-z', 'emulator', 'ARS', 1, $hostile, ['http://127.0.0.1:55455'], true);
        self::assertSame(['processed' => 1, 'deferred' => 1], $worker->reconcile(200));
        self::assertSame(0, $hostileReads);
        self::assertSame(['ORD-b'], $reads);
        self::assertSame('captured', $this->store->intent($sibling['reference'])['state']);
        $unchanged();

        // 2. Alias a is retired altogether: history fails closed, nothing is read or written.
        unset($this->accounts->accounts['a']);
        $due();
        self::assertSame(['processed' => 0, 'deferred' => 1], $worker->reconcile(200));
        self::assertSame(['ORD-b'], $reads);
        $unchanged();

        // 3. Alias a returns for the ORIGINAL merchant with rotated credentials, still not the default.
        $this->accounts->accounts['a'] = $this->account(version: 2);
        $due();
        self::assertSame(['processed' => 1, 'deferred' => 0], $worker->reconcile(200));
        self::assertSame(['ORD-b', 'ORD-test'], $reads);
        self::assertSame('captured', $this->store->intent($base['reference'])['state']);
        self::assertSame(0, $hostileReads);
        self::assertSame(1, $this->posts);
        self::assertSame(2, (int) $this->db->query('SELECT count(*) FROM transactions')->fetchColumn());
    }

    public function test_unknown_refund_keeps_reservation_and_individual_confirmation_consumes_it_once(): void
    {
        $this->remote = fn (CreatePayment $request) => $this->payment($request->reference, PaymentStatus::Captured);
        $payment = $this->start();
        $refund = $this->store->reserveRefund($payment['reference'], 'refund-1', 8000);
        $this->store->markRefundUncertain($refund['reference']);
        try {
            $this->store->reserveRefund($payment['reference'], 'refund-2', 4001);
            self::fail('Over-refund allowed.');
        } catch (PaymentError $error) {
            self::assertSame('insufficient_refundable_balance', $error->reason);
        }
        $this->store->confirmRefund($refund['reference'], 'remote-refund-1', new Money(8000, 'ARS'));
        $this->store->confirmRefund($refund['reference'], 'remote-refund-1', new Money(8000, 'ARS'));
        self::assertSame('8000', $this->store->intent($payment['reference'])['refunded_minor']);
        self::assertSame(1, (int) $this->db->query("SELECT count(*) FROM transactions WHERE kind='refund'")->fetchColumn());
        $rest = $this->store->reserveRefund($payment['reference'], 'refund-2', 4000);
        $this->store->confirmRefund($rest['reference'], 'remote-refund-2', new Money(4000, 'ARS'));
        self::assertSame('refunded', $this->store->intent($payment['reference'])['state']);
        self::assertSame(1, $this->posts);
    }

    public function test_refund_timeout_reconstructs_recovers_individual_evidence_and_never_resends(): void
    {
        $this->remote = fn (CreatePayment $request) => $this->payment($request->reference, PaymentStatus::Captured);
        $intent = $this->start();
        $actor = new Actor('invoices', 'tenant-a', 'buyer-a', ['a'], ['refund','read']);
        $reader = $this->createMock(RefundEvidenceReader::class);
        $this->gateway->method('supports')->with(Capability::PartialRefund)->willReturn(true);
        $observed = [];
        $this->gateway->expects(self::once())->method('refund')->willReturnCallback(function ($id, $money, $key) use (&$observed) {
            $observed = [$this->db->inTransaction(),$this->db->query('SELECT state FROM refunds')->fetchColumn(),$key];
            throw new \RuntimeException('Timeout after remote acceptance');
        });
        $service = new RefundCoordinator($this->store, $this->accounts, $reader, true);
        $first = $service->request($actor, $intent['reference'], new Money(8000, 'ARS'), 'refund-timeout');
        self::assertSame('UNKNOWN', $first['operationState']);
        self::assertSame(202, $first['httpStatus']);
        self::assertFalse($observed[0]);
        self::assertSame('SENDING', $observed[1]);
        $stored = $this->store->refundOperation($first['operationReference']);
        self::assertSame($stored['remote_key'], $observed[2]);
        self::assertSame($first, $service->request($actor, $intent['reference'], new Money(8000, 'ARS'), 'refund-timeout'));
        try {
            $service->request($actor, $intent['reference'], new Money(4001, 'ARS'), 'refund-over');
            self::fail('Uncertain reservation lost');
        } catch (PaymentError $e) {
            self::assertSame('insufficient_refundable_balance', $e->reason);
        }
        $reader->expects(self::once())->method('read')->willReturnCallback(function ($account, $paymentId, $refundId, $key) use ($stored) {
            self::assertFalse($this->db->inTransaction());
            self::assertSame('ORD-test', $paymentId);
            self::assertNull($refundId);
            self::assertSame($stored['remote_key'], $key);
            return new Refund('remote-refund', new Money(8000, 'ARS'), 'processed', $paymentId);
        });
        // Disabled new commands do not disable recovery of accepted historical commands.
        $reconstructed = new RefundCoordinator($this->store, $this->accounts, $reader);
        self::assertTrue($reconstructed->recover($first['operationReference']));
        self::assertTrue($reconstructed->recover($first['operationReference']));
        self::assertSame('COMPLETED', $reconstructed->view($actor, $first['operationReference'])['operationState']);
        self::assertSame('8000', $this->store->intent($intent['reference'])['refunded_minor']);
        self::assertSame(1, (int)$this->db->query("SELECT count(*) FROM transactions WHERE kind='refund'")->fetchColumn());
        self::assertStringNotContainsString('remote_key', json_encode($first));
    }

    public function test_pending_refund_is_not_money_and_wrong_evidence_does_not_release_it(): void
    {
        $this->remote = fn (CreatePayment $request) => $this->payment($request->reference, PaymentStatus::Captured);
        $intent = $this->start();
        $actor = new Actor('invoices', 'tenant-a', 'buyer-a', ['a'], ['refund','read']);
        $reader = $this->createMock(RefundEvidenceReader::class);
        $this->gateway->method('supports')->willReturn(true);
        $this->gateway->expects(self::once())->method('refund')->willReturn(new Refund('refund-pending', new Money(4000, 'ARS'), 'pending', 'ORD-test'));
        $service = new RefundCoordinator($this->store, $this->accounts, $reader, true);
        $result = $service->request($actor, $intent['reference'], new Money(4000, 'ARS'), 'pending-request');
        self::assertSame('PENDING', $result['operationState']);
        self::assertSame('0', $this->store->intent($intent['reference'])['refunded_minor']);
        $reader->method('read')->willReturn(new Refund('wrong-identity', new Money(4000, 'ARS'), 'processed', 'ORD-test'));
        try {
            $service->recover($result['operationReference']);
            self::fail('Changed refund identity');
        } catch (PaymentError $e) {
            self::assertSame('refund_evidence_invalid', $e->reason);
        }
        self::assertSame('PENDING', $service->view($actor, $result['operationReference'])['operationState']);
        $this->store->confirmRefund($result['operationReference'], 'refund-pending', new Money(4000, 'ARS'));
        $this->store->observePendingRefund($result['operationReference'], 'refund-pending');
        self::assertSame('COMPLETED', $service->view($actor, $result['operationReference'])['operationState']);
        $other = new Actor('invoices', 'tenant-a', 'buyer-b', ['a'], ['refund','read']);
        try {
            $service->view($other, $result['operationReference']);
            self::fail('Other owner read');
        } catch (PaymentError $e) {
            self::assertSame('not_found', $e->reason);
        }
    }

    public function test_refund_capability_and_default_disabled_gate_precede_reservation_and_io(): void
    {
        $this->remote = fn (CreatePayment $request) => $this->payment($request->reference, PaymentStatus::Captured);
        $intent = $this->start();
        $reader = $this->createMock(RefundEvidenceReader::class);
        $this->gateway->expects(self::never())->method('refund');
        $reader->expects(self::never())->method('read');
        $service = new RefundCoordinator($this->store, $this->accounts, $reader);
        try {
            $service->request($this->actor, $intent['reference'], new Money(100, 'ARS'), 'blocked');
            self::fail('Implicit refund permission');
        } catch (PaymentError $e) {
            self::assertSame('forbidden', $e->reason);
        }
        $actor = new Actor('invoices', 'tenant-a', 'buyer-a', ['a'], ['refund']);
        try {
            $service->request($actor, $intent['reference'], new Money(100, 'ARS'), 'disabled');
            self::fail('Refunds enabled');
        } catch (PaymentError $e) {
            self::assertSame('refunds_disabled', $e->reason);
        }
        self::assertSame(0, (int)$this->db->query('SELECT count(*) FROM refunds')->fetchColumn());
    }

    public function test_refund_id_cannot_settle_two_reservations_and_rollback_keeps_second_reserved(): void
    {
        $this->remote = fn (CreatePayment $request) => $this->payment($request->reference, PaymentStatus::Captured);
        $payment = $this->start();
        $one = $this->store->reserveRefund($payment['reference'], 'refund-1', 2000);
        $two = $this->store->reserveRefund($payment['reference'], 'refund-2', 2000);
        $this->store->confirmRefund($one['reference'], 'remote-same', new Money(2000, 'ARS'));
        try {
            $this->store->confirmRefund($two['reference'], 'remote-same', new Money(2000, 'ARS'));
            self::fail();
        } catch (PaymentError $error) {
            self::assertSame('refund_identity_already_used', $error->reason);
        }
        self::assertSame('PREPARED', $this->store->reserveRefund($payment['reference'], 'refund-2', 2000)['state']);
        self::assertSame('2000', $this->store->intent($payment['reference'])['refunded_minor']);
    }

    public function test_recovery_commits_confirmed_remote_capture_without_repeating_post(): void
    {
        $this->remote = function () {
            throw new \RuntimeException('Lost response after capture.');
        };
        $pending = $this->start();
        self::assertTrue($this->coordinator->recoverOperation($pending['operationReference'], $this->payment($pending['reference'], PaymentStatus::Captured), $this->store->intent($pending['reference'])['version']));
        $confirmed = $this->coordinator->operationView($this->actor, $pending['operationReference']);
        self::assertSame('CONFIRMED', $confirmed['operationState']);
        self::assertSame('captured', $confirmed['financialState']);
        self::assertSame(1, $this->posts);
        self::assertSame(1, (int) $this->db->query('SELECT count(*) FROM outbox')->fetchColumn());
    }

    public function test_capture_observation_cannot_erase_a_confirmed_refund(): void
    {
        $this->remote = fn (CreatePayment $request) => $this->payment($request->reference, PaymentStatus::Captured);
        $payment = $this->start();
        $refund = $this->store->reserveRefund($payment['reference'], 'refund-1', 2000);
        $this->store->confirmRefund($refund['reference'], 'remote-refund-1', new Money(2000, 'ARS'));
        $current = $this->store->intent($payment['reference']);
        try {
            $this->coordinator->apply($current, $this->payment($payment['reference'], PaymentStatus::Captured), $this->account());
            self::fail();
        } catch (PaymentError $error) {
            self::assertSame('stale_refund_observation', $error->reason);
        }
        self::assertSame($current, $this->store->intent($payment['reference']));
    }

    public function test_refunded_total_on_captured_status_cannot_be_ignored_or_inferred_into_a_movement(): void
    {
        $this->remote = fn (CreatePayment $request) => $this->payment($request->reference, PaymentStatus::Captured);
        $view = $this->start();
        $intent = $this->store->intent($view['reference']);
        $payment = new Payment('mercadopago', 'ORD-test', PaymentStatus::Captured, new Money(12000, 'ARS'), $intent['reference'], 'processed', transactionId:'transaction-test', capturedAmount:new Money(12000, 'ARS'), refundedAmount:new Money(2000, 'ARS'));
        try {
            $this->coordinator->apply($intent, $payment, $this->account());
            self::fail('External refunded total was silently ignored');
        } catch (PaymentError $e) {
            self::assertSame('external_adjustment_requires_evidence', $e->reason);
        }
        self::assertSame('0', $this->store->intent($intent['reference'])['refunded_minor']);
        self::assertSame(0, (int)$this->db->query("SELECT count(*) FROM transactions WHERE kind='refund'")->fetchColumn());
    }

    public function test_delivery_failure_keeps_capture_and_retries_with_stable_event_identity(): void
    {
        $this->remote = fn (CreatePayment $request) => $this->payment($request->reference, PaymentStatus::Captured);
        $payment = $this->start();
        $product = new class () implements Fulfillment {
            public array $events = [];
            public function deliver(string $eventId, string $subject, array $payment): bool
            {
                $this->events[] = $eventId;
                if (count($this->events) === 1) {
                    throw new \RuntimeException('business store unavailable');
                }
                return true;
            }
            public function compensate(string $eventId, string $subject, array $payment): void
            {
                throw new \RuntimeException('unexpected');
            }
        };
        $worker = new RecoveryWorker($this->store, $this->coordinator, $this->accounts, $product);
        self::assertSame(['delivered' => 0,'failed' => 1], $worker->outbox());
        self::assertSame('captured', $this->store->intent($payment['reference'])['state']);
        self::assertSame('pending', $this->store->intent($payment['reference'])['fulfillment']);
        self::assertSame(['delivered' => 0,'failed' => 0], $worker->outbox());
        $this->db->exec("UPDATE outbox SET next_due=clock_timestamp()-interval '1 second'");
        self::assertSame(['delivered' => 1,'failed' => 0], $worker->outbox());
        self::assertSame($product->events[0], $product->events[1]);
        self::assertSame('delivered', $this->store->intent($payment['reference'])['fulfillment']);
    }

    public function test_late_unfulfillable_capture_requests_compensation_without_a_booking(): void
    {
        $this->remote = fn (CreatePayment $request) => $this->payment($request->reference, PaymentStatus::Captured);
        $payment = $this->start();
        $product = new class () implements Fulfillment {
            public array $compensations = [];
            public function deliver(string $eventId, string $subject, array $payment): bool
            {
                return false;
            }
            public function compensate(string $eventId, string $subject, array $payment): void
            {
                $this->compensations[$eventId] = $subject;
            }
        };
        $worker = new RecoveryWorker($this->store, $this->coordinator, $this->accounts, $product);
        self::assertSame(['delivered' => 1,'failed' => 0], $worker->outbox());
        self::assertCount(1, $product->compensations);
        self::assertSame('compensation_requested', $this->store->intent($payment['reference'])['fulfillment']);
        self::assertSame('captured', $this->store->intent($payment['reference'])['state']);
    }

    public function test_compensation_failure_persists_rejection_and_recovery_never_attempts_delivery_again(): void
    {
        $this->remote = fn (CreatePayment $request) => $this->payment($request->reference, PaymentStatus::Captured);
        $payment = $this->start();
        $product = new class () implements Fulfillment {
            public int $deliveries = 0;
            public array $compensations = [];
            public function deliver(string $eventId, string $subject, array $payment): bool
            {
                $this->deliveries++;
                return $this->deliveries > 1;
            }
            public function compensate(string $eventId, string $subject, array $payment): void
            {
                $this->compensations[] = $eventId;
                if (count($this->compensations) === 1) {
                    throw new \RuntimeException('product store unavailable');
                }
            }
        };
        $worker = new RecoveryWorker($this->store, $this->coordinator, $this->accounts, $product);
        self::assertSame(['delivered' => 0,'failed' => 1], $worker->outbox());
        self::assertSame('compensation_required', $this->store->intent($payment['reference'])['fulfillment']);
        self::assertSame('captured', $this->store->intent($payment['reference'])['state']);
        try {
            $this->store->finishFulfillment($payment['reference'], 'delivered');
            self::fail('Irreversible decision changed');
        } catch (PaymentError $e) {
            self::assertSame('fulfillment_conflict', $e->reason);
        }
        $this->db->exec("UPDATE outbox SET next_due=clock_timestamp()-interval '1 second'");
        $worker = new RecoveryWorker($this->store, $this->coordinator, $this->accounts, $product);
        self::assertSame(['delivered' => 1,'failed' => 0], $worker->outbox());
        self::assertSame(1, $product->deliveries);
        self::assertSame($product->compensations[0], $product->compensations[1]);
        self::assertSame('compensation_requested', $this->store->intent($payment['reference'])['fulfillment']);
        self::assertSame('pending', $this->store->intent($payment['reference'])['compensation']);
    }

    public function test_financial_compensation_reserves_once_and_unknown_recovers_without_resending(): void
    {
        $this->remote = fn (CreatePayment $r) => $this->payment($r->reference, PaymentStatus::Captured);
        $payment = $this->start();
        $reader = $this->createMock(RefundEvidenceReader::class);
        $service = new RefundCoordinator($this->store, $this->accounts, $reader, true);
        try {
            $service->requestCompensation($payment['reference']);
            self::fail('No rejection');
        } catch (PaymentError $e) {
            self::assertSame('compensation_not_required', $e->reason);
        }
        $refundActor = new Actor('invoices', 'tenant-a', 'buyer-a', ['a'], ['refund']);
        try {
            $service->request($refundActor, $payment['reference'], new Money(12000, 'ARS'), 'compensation:v1:'.$payment['reference']);
            self::fail('Reserved key accepted');
        } catch (PaymentError $e) {
            self::assertSame('reserved_idempotency_key', $e->reason);
        }
        $this->store->finishFulfillment($payment['reference'], 'compensation_required');
        $disabled = new RefundCoordinator($this->store, $this->accounts, $reader);
        try {
            $disabled->requestCompensation($payment['reference']);
            self::fail('Gate bypassed');
        } catch (PaymentError $e) {
            self::assertSame('refunds_disabled', $e->reason);
        }
        $io = [];
        $this->gateway->expects(self::once())->method('refund')->willReturnCallback(function (string $id, Money $amount, string $key) use (&$io) {
            $io[] = [$this->db->inTransaction(),$this->db->query('SELECT state FROM refunds')->fetchColumn(),$amount->minor];
            throw new \RuntimeException('response lost after refund');
        });
        $first = $service->requestCompensation($payment['reference']);
        self::assertSame('UNKNOWN', $first['operationState']);
        self::assertSame([[false,'SENDING',12000]], $io);
        $service = new RefundCoordinator($this->store, $this->accounts, $reader, true);
        self::assertSame($first, $service->requestCompensation($payment['reference']));
        self::assertSame('pending', $this->store->intent($payment['reference'])['compensation']);
        self::assertSame(1, (int)$this->db->query('SELECT count(*) FROM refunds')->fetchColumn());
        $reader->expects(self::once())->method('read')->willReturn(new Refund('compensation-remote', new Money(12000, 'ARS'), 'approved', 'ORD-test'));
        self::assertTrue($disabled->recover($first['operationReference']));
        self::assertSame('completed', $this->store->intent($payment['reference'])['compensation']);
        self::assertSame('refunded', $this->store->intent($payment['reference'])['state']);
        self::assertSame('COMPLETED', $service->requestCompensation($payment['reference'])['operationState']);
        $this->store->finishFulfillment($payment['reference'], 'compensation_requested');
        self::assertSame('completed', $this->coordinator->operationView($this->actor, $payment['operationReference'])['compensationState']);
        self::assertSame(1, (int)$this->db->query("SELECT count(*) FROM transactions WHERE kind='refund'")->fetchColumn());
    }

    public function test_confirmed_compensation_survives_product_callback_crash_and_does_not_skip_its_retry(): void
    {
        $this->remote = fn (CreatePayment $r) => $this->payment($r->reference, PaymentStatus::Captured);
        $payment = $this->start();
        $this->gateway->expects(self::once())->method('refund')->willReturn(new Refund('compensation-confirmed', new Money(12000, 'ARS'), 'approved', 'ORD-test'));
        $refunds = new RefundCoordinator($this->store, $this->accounts, $this->createMock(RefundEvidenceReader::class), true);
        $product = new class ($refunds) implements Fulfillment {
            public int $deliveries = 0;
            public int $compensations = 0;
            public function __construct(private RefundCoordinator $refunds)
            {
            }
            public function deliver(string $eventId, string $subject, array $payment): bool
            {
                $this->deliveries++;
                return false;
            }
            public function compensate(string $eventId, string $subject, array $payment): void
            {
                $this->refunds->requestCompensation($payment['reference']);
                if (++$this->compensations === 1) {
                    throw new \RuntimeException('crash before product acknowledgment');
                }
            }
        };
        $worker = new RecoveryWorker($this->store, $this->coordinator, $this->accounts, $product);
        self::assertSame(['delivered' => 0,'failed' => 1], $worker->outbox());
        self::assertSame('compensation_required', $this->store->intent($payment['reference'])['fulfillment']);
        self::assertSame('completed', $this->store->intent($payment['reference'])['compensation']);
        $this->db->exec("UPDATE outbox SET next_due=clock_timestamp()-interval '1 second'");
        $worker = new RecoveryWorker($this->store, $this->coordinator, $this->accounts, $product);
        self::assertSame(['delivered' => 1,'failed' => 0], $worker->outbox());
        self::assertSame(1, $product->deliveries);
        self::assertSame(2, $product->compensations);
        self::assertSame('compensation_requested', $this->store->intent($payment['reference'])['fulfillment']);
        self::assertSame('completed', $this->store->intent($payment['reference'])['compensation']);
    }

    public function test_partial_refund_before_delivery_cannot_deliver_the_original_invoice(): void
    {
        $this->remote = fn (CreatePayment $r) => $this->payment($r->reference, PaymentStatus::Captured);
        $payment = $this->start();
        $refund = $this->store->reserveRefund($payment['reference'], 'customer-refund', 2000);
        $this->store->confirmRefund($refund['reference'], 'external-before-delivery', new Money(2000, 'ARS'));
        $product = $this->createMock(Fulfillment::class);
        $product->expects(self::never())->method('deliver');
        $product->expects(self::once())->method('compensate');
        $worker = new RecoveryWorker($this->store, $this->coordinator, $this->accounts, $product);
        self::assertSame(['delivered' => 1,'failed' => 0], $worker->outbox());
        self::assertSame('compensation_requested', $this->store->intent($payment['reference'])['fulfillment']);
        self::assertSame('partially_refunded', $this->store->intent($payment['reference'])['state']);
    }

    public function test_fully_refunded_before_delivery_requires_no_second_compensation_transfer(): void
    {
        $this->remote = fn (CreatePayment $r) => $this->payment($r->reference, PaymentStatus::Captured);
        $payment = $this->start();
        $refund = $this->store->reserveRefund($payment['reference'], 'customer-full-refund', 12000);
        $this->store->confirmRefund($refund['reference'], 'refund-before-delivery', new Money(12000, 'ARS'));
        $this->gateway->expects(self::never())->method('refund');
        $service = new RefundCoordinator($this->store, $this->accounts, $this->createMock(RefundEvidenceReader::class));
        $product = new class ($service) implements Fulfillment {
            public function __construct(private RefundCoordinator $refunds)
            {
            }
            public function deliver(string $eventId, string $subject, array $payment): bool
            {
                throw new \RuntimeException('Refunded payment delivered');
            }
            public function compensate(string $eventId, string $subject, array $payment): void
            {
                if ($this->refunds->requestCompensation($payment['reference']) !== ['operationReference' => null,'operationState' => 'COMPLETED']) {
                    throw new \RuntimeException('Expected existing full refund');
                }
            }
        };
        $worker = new RecoveryWorker($this->store, $this->coordinator, $this->accounts, $product);
        self::assertSame(['delivered' => 1,'failed' => 0], $worker->outbox());
        self::assertSame('completed', $this->store->intent($payment['reference'])['compensation']);
        self::assertSame(1, (int)$this->db->query('SELECT count(*) FROM refunds')->fetchColumn());
    }

    public function test_external_refund_commits_observation_ledger_and_event_once_without_releasing_unknown_reservation(): void
    {
        $this->remote = fn (CreatePayment $r) => $this->payment($r->reference, PaymentStatus::Captured);
        $payment = $this->start();
        $reservation = $this->store->reserveRefund($payment['reference'], 'uncertain-local-refund', 2000);
        $this->store->markRefundUncertain($reservation['reference']);
        $reader = $this->createMock(RefundEvidenceReader::class);
        $io = [];
        $reader->expects(self::exactly(2))->method('read')->willReturnCallback(function (Account $account, string $paymentId, ?string $refundId, string $remoteKey) use (&$io) {
            $io[] = [$this->db->inTransaction(),$paymentId,$refundId,$remoteKey];
            return new Refund('individual-external', new Money(2000, 'ARS'), 'approved', 'ORD-test', raw:['buyer_email' => 'private@example.test']);
        });
        $service = new ExternalRefundCoordinator($this->store, $this->accounts, $reader);
        self::assertTrue($service->observe($payment['reference'], 'individual-external'));
        self::assertTrue($service->observe($payment['reference'], 'individual-external'));
        self::assertSame([[false,'ORD-test','individual-external',''],[false,'ORD-test','individual-external','']], $io);
        self::assertSame('UNKNOWN', $this->store->refundOperation($reservation['reference'])['state']);
        self::assertSame('2000', $this->store->intent($payment['reference'])['refunded_minor']);
        self::assertSame(1, (int)$this->db->query("SELECT count(*) FROM operations WHERE kind='observation'")->fetchColumn());
        self::assertSame(1, (int)$this->db->query("SELECT count(*) FROM outbox WHERE type='payment.refunded'")->fetchColumn());
        self::assertStringNotContainsString('private@example.test', json_encode($this->db->query('SELECT data FROM operations UNION ALL SELECT payload FROM outbox')->fetchAll(PDO::FETCH_COLUMN)));
        try {
            $this->store->reserveRefund($payment['reference'], 'over-reservation', 8001);
            self::fail('Unknown reservation was released');
        } catch (PaymentError $e) {
            self::assertSame('insufficient_refundable_balance', $e->reason);
        }
        $this->store->confirmRefund($reservation['reference'], 'individual-external', new Money(2000, 'ARS'));
        self::assertSame(1, (int)$this->db->query("SELECT count(*) FROM transactions WHERE kind='refund'")->fetchColumn());
        self::assertSame('COMPLETED', $this->store->refundOperation($reservation['reference'])['state']);
        self::assertSame('PREPARED', $this->store->reserveRefund($payment['reference'], 'remaining-balance', 10000)['state']);
    }

    public function test_external_refund_stale_read_and_mismatch_never_change_money(): void
    {
        $this->remote = fn (CreatePayment $r) => $this->payment($r->reference, PaymentStatus::Captured);
        $payment = $this->start();
        $reader = $this->createMock(RefundEvidenceReader::class);
        $reader->method('read')->willReturnCallback(function () use ($payment) {
            $this->store->transaction(function () use ($payment) {
                $intent = $this->store->intent($payment['reference'], true);
                $version = $intent['version'];
                $intent['version']++;
                $this->store->saveIntent($intent, $version);
            });
            return new Refund('stale-refund', new Money(2000, 'ARS'), 'approved', 'ORD-test');
        });
        self::assertFalse((new ExternalRefundCoordinator($this->store, $this->accounts, $reader))->observe($payment['reference'], 'stale-refund'));
        $invalid = $this->createMock(RefundEvidenceReader::class);
        $invalid->method('read')->willReturnOnConsecutiveCalls(null, new Refund('wrong-id', new Money(2000, 'ARS'), 'approved', 'ORD-test'), new Refund('expected', new Money(2000, 'USD'), 'approved', 'ORD-test'), new Refund('expected', new Money(2000, 'ARS'), 'pending', 'ORD-test'), new Refund('expected', new Money(2000, 'ARS'), 'approved', 'other-payment'));
        $service = new ExternalRefundCoordinator($this->store, $this->accounts, $invalid);
        self::assertFalse($service->observe($payment['reference'], 'expected'));
        for ($i = 0;$i < 4;$i++) {
            try {
                $service->observe($payment['reference'], 'expected');
                self::fail('Invalid evidence accepted');
            } catch (PaymentError $e) {
                self::assertSame('refund_evidence_invalid', $e->reason);
            }
        }
        self::assertSame('0', $this->store->intent($payment['reference'])['refunded_minor']);
        self::assertSame(0, (int)$this->db->query("SELECT count(*) FROM transactions WHERE kind='refund'")->fetchColumn());
        $never = $this->createMock(RefundEvidenceReader::class);
        $never->expects(self::never())->method('read');
        $this->db->beginTransaction();
        try {
            (new ExternalRefundCoordinator($this->store, $this->accounts, $never))->observe($payment['reference'], 'expected');
            self::fail('Provider read inside transaction');
        } catch (PaymentError $e) {
            self::assertSame('provider_io_inside_transaction', $e->reason);
        } finally {
            $this->db->rollBack();
        }
    }

    public function test_external_refund_outbox_failure_rolls_back_all_financial_writes(): void
    {
        $this->remote = fn (CreatePayment $r) => $this->payment($r->reference, PaymentStatus::Captured);
        $payment = $this->start();
        $this->db->exec("ALTER TABLE outbox ADD CONSTRAINT reject_refunded CHECK(type<>'payment.refunded')");
        $reader = $this->createMock(RefundEvidenceReader::class);
        $reader->method('read')->willReturn(new Refund('rollback-refund', new Money(2000, 'ARS'), 'approved', 'ORD-test'));
        try {
            (new ExternalRefundCoordinator($this->store, $this->accounts, $reader))->observe($payment['reference'], 'rollback-refund');
            self::fail('Expected outbox failure');
        } catch (\PDOException $e) {
            self::assertSame('23514', $e->getCode());
        }
        self::assertSame(0, (int)$this->db->query("SELECT count(*) FROM operations WHERE kind='observation'")->fetchColumn());
        self::assertSame(0, (int)$this->db->query("SELECT count(*) FROM transactions WHERE kind='refund'")->fetchColumn());
        self::assertSame('captured', $this->store->intent($payment['reference'])['state']);
        self::assertSame('0', $this->store->intent($payment['reference'])['refunded_minor']);
    }

    public function test_external_refund_event_requires_a_consumer_and_retries_with_stable_identity(): void
    {
        $this->remote = fn (CreatePayment $r) => $this->payment($r->reference, PaymentStatus::Captured);
        $payment = $this->start();
        $product = $this->createMock(Fulfillment::class);
        $product->method('deliver')->willReturn(true);
        $worker = new RecoveryWorker($this->store, $this->coordinator, $this->accounts, $product);
        self::assertSame(['delivered' => 1,'failed' => 0], $worker->outbox());
        $reader = $this->createMock(RefundEvidenceReader::class);
        $reader->method('read')->willReturn(new Refund('event-refund', new Money(2000, 'ARS'), 'approved', 'ORD-test'));
        self::assertTrue((new ExternalRefundCoordinator($this->store, $this->accounts, $reader))->observe($payment['reference'], 'event-refund'));
        self::assertSame(['delivered' => 0,'failed' => 1], $worker->outbox());
        $consumer = new class () implements FinancialEventConsumer {
            public array $ids = [];
            public function consume(string $eventId, string $type, array $payload): void
            {
                $this->ids[] = $eventId;
                if ($type !== 'payment.refunded' || $payload['minor'] !== '2000') {
                    throw new \RuntimeException('Wrong event');
                }
                if (count($this->ids) === 1) {
                    throw new \RuntimeException('Consumer unavailable');
                }
            }
        };
        $worker = new RecoveryWorker($this->store, $this->coordinator, $this->accounts, $product, $consumer);
        $this->db->exec("UPDATE outbox SET next_due=clock_timestamp()-interval '1 second'");
        self::assertSame(['delivered' => 0,'failed' => 1], $worker->outbox());
        $this->db->exec("UPDATE outbox SET next_due=clock_timestamp()-interval '1 second'");
        self::assertSame(['delivered' => 1,'failed' => 0], $worker->outbox());
        self::assertSame($consumer->ids[0], $consumer->ids[1]);
        self::assertSame('delivered', $this->store->intent($payment['reference'])['fulfillment']);
    }

    public function test_external_refund_total_above_signed_bigint_is_rejected_before_php_can_saturate_it(): void
    {
        $amount = new Money(PHP_INT_MAX, 'ARS');
        $this->subjects->quotes['invoice:uuid-example'] = new Quote('invoice:uuid-example', 'v1', $this->actor->scope(), $amount, 2000);
        $this->remote = fn (CreatePayment $r) => new Payment('mercadopago', 'ORD-test', PaymentStatus::Captured, $amount, $r->reference, 'test', transactionId:'transaction-test', capturedAmount:$amount);
        $payment = $this->start();
        $first = $this->store->reserveRefund($payment['reference'], 'large-refund', PHP_INT_MAX - 1);
        $this->store->confirmRefund($first['reference'], 'large-remote-refund', new Money(PHP_INT_MAX - 1, 'ARS'));
        $reader = $this->createMock(RefundEvidenceReader::class);
        $reader->method('read')->willReturn(new Refund('overflow-refund', new Money(2, 'ARS'), 'approved', 'ORD-test'));
        try {
            (new ExternalRefundCoordinator($this->store, $this->accounts, $reader))->observe($payment['reference'], 'overflow-refund');
            self::fail('Overflow accepted');
        } catch (PaymentError $e) {
            self::assertSame('refund_balance_mismatch', $e->reason);
        }
        self::assertSame((string)(PHP_INT_MAX - 1), $this->store->intent($payment['reference'])['refunded_minor']);
        self::assertSame(1, (int)$this->db->query("SELECT count(*) FROM transactions WHERE kind='refund'")->fetchColumn());
    }

    public function test_dispute_open_won_lifecycle_is_idempotent_and_does_not_become_a_refund(): void
    {
        $this->remote = fn (CreatePayment $r) => $this->payment($r->reference, PaymentStatus::Captured);
        $payment = $this->start();
        $prepared = $this->store->reserveRefund($payment['reference'], 'prepared-before-dispute', 2000);
        $reader = $this->createMock(DisputeEvidenceReader::class);
        $evidence = new DisputeEvidence('dispute-a', 'ORD-test', new Money(4000, 'ARS'), 'opened');
        $io = [];
        $reader->method('read')->willReturnCallback(function (Account $a, string $paymentId, string $id) use (&$evidence, &$io) {
            $io[] = [$this->db->inTransaction(),$paymentId,$id];
            return $evidence;
        });
        $service = new DisputeCoordinator($this->store, $this->accounts, $reader);
        self::assertTrue($service->observe($payment['reference'], 'dispute-a'));
        self::assertTrue($service->observe($payment['reference'], 'dispute-a'));
        $intent = $this->store->intent($payment['reference']);
        self::assertSame('disputed', $intent['state']);
        self::assertSame('4000', $intent['disputed_minor']);
        self::assertSame('0', $intent['refunded_minor']);
        foreach (['reserve','claim','capture'] as $action) {
            try {
                match($action) {
                    'reserve' => $this->store->reserveRefund($payment['reference'], 'during-dispute', 100),'claim' => $this->store->claimRefund($prepared['reference']),'capture' => $this->coordinator->apply($intent, $this->payment($payment['reference'], PaymentStatus::Captured), $this->account())
                };
                self::fail('Dispute protection bypassed');
            } catch (PaymentError $e) {
                self::assertSame('dispute_requires_review', $e->reason);
            }
        }
        $evidence = new DisputeEvidence('dispute-a', 'ORD-test', new Money(4000, 'ARS'), 'won');
        self::assertTrue($service->observe($payment['reference'], 'dispute-a'));
        self::assertTrue($service->observe($payment['reference'], 'dispute-a'));
        $intent = $this->store->intent($payment['reference']);
        self::assertSame('captured', $intent['state']);
        self::assertSame('0', $intent['disputed_minor']);
        self::assertSame('0', $intent['charged_back_minor']);
        self::assertSame(2, (int)$this->db->query("SELECT count(*) FROM transactions WHERE kind LIKE 'dispute_%'")->fetchColumn());
        self::assertSame(2, (int)$this->db->query("SELECT count(*) FROM outbox WHERE type='payment.dispute_updated'")->fetchColumn());
        self::assertSame(1, (int)$this->db->query("SELECT count(*) FROM operations WHERE kind='dispute'")->fetchColumn());
        self::assertSame('PREPARED', $this->store->reserveRefund($payment['reference'], 'after-win', 10000)['state']);
        foreach (['opened','lost'] as $state) {
            $evidence = new DisputeEvidence('dispute-a', 'ORD-test', new Money(4000, 'ARS'), $state);
            try {
                $service->observe($payment['reference'], 'dispute-a');
                self::fail('Terminal outcome changed');
            } catch (PaymentError $e) {
                self::assertSame('stale_dispute_observation', $e->reason);
            }
        }
        foreach ($io as $observation) {
            self::assertSame([false,'ORD-test','dispute-a'], $observation);
        }
    }

    public function test_worker_recovers_known_dispute_readonly_and_does_not_reset_pending_backoff(): void
    {
        $this->remote = fn (CreatePayment $r) => $this->payment($r->reference, PaymentStatus::Captured);
        $payment = $this->start();
        $reader = $this->createMock(DisputeEvidenceReader::class);
        $state = 'opened';
        $reader->method('read')->willReturnCallback(function (Account $account, string $paymentId, string $id) use (&$state) {
            self::assertFalse($this->db->inTransaction());
            self::assertSame('known-case', $id);
            return new DisputeEvidence($id, $paymentId, new Money(2000, 'ARS'), $state);
        });
        $disputes = new DisputeCoordinator($this->store, $this->accounts, $reader);
        $disputes->observe($payment['reference'], 'known-case');
        self::assertSame(['known-case'], $this->store->pendingDisputeIds($payment['reference']));
        $worker = new RecoveryWorker($this->store, $this->coordinator, $this->accounts, $this->createMock(Fulfillment::class), disputes: $disputes);
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->db->exec("UPDATE reconciliation SET reconcile_due=clock_timestamp()-interval '1 second'");
            self::assertSame(['processed' => 0, 'deferred' => 1], $worker->reconcile());
            self::assertSame($attempt, (int) $this->db->query('SELECT reconcile_attempts FROM reconciliation')->fetchColumn());
        }
        $state = 'won';
        $this->db->exec("UPDATE reconciliation SET reconcile_due=clock_timestamp()-interval '1 second'");
        self::assertSame(['processed' => 1, 'deferred' => 0], $worker->reconcile());
        self::assertSame([], $this->store->pendingDisputeIds($payment['reference']));
        self::assertSame('captured', $this->store->intent($payment['reference'])['state']);
        self::assertSame(1, $this->posts);
    }

    public function test_lost_dispute_is_distinct_from_refund_and_late_refund_confirmation_preserves_it(): void
    {
        $this->remote = fn (CreatePayment $r) => $this->payment($r->reference, PaymentStatus::Captured);
        $payment = $this->start();
        $refund = $this->store->reserveRefund($payment['reference'], 'sent-before-dispute', 2000);
        $this->store->claimRefund($refund['reference']);
        $reader = $this->createMock(DisputeEvidenceReader::class);
        $reader->method('read')->willReturn(new DisputeEvidence('lost-dispute', 'ORD-test', new Money(4000, 'ARS'), 'lost'));
        $service = new DisputeCoordinator($this->store, $this->accounts, $reader);
        self::assertTrue($service->observe($payment['reference'], 'lost-dispute'));
        self::assertTrue($service->observe($payment['reference'], 'lost-dispute'));
        $this->store->confirmRefund($refund['reference'], 'late-refund', new Money(2000, 'ARS'));
        $intent = $this->store->intent($payment['reference']);
        self::assertSame('disputed', $intent['state']);
        self::assertSame('0', $intent['disputed_minor']);
        self::assertSame('4000', $intent['charged_back_minor']);
        self::assertSame('2000', $intent['refunded_minor']);
        self::assertSame('12000', $intent['captured_minor']);
        self::assertSame(1, (int)$this->db->query("SELECT count(*) FROM transactions WHERE kind='dispute_lost'")->fetchColumn());
        self::assertSame(1, (int)$this->db->query("SELECT count(*) FROM transactions WHERE kind='refund'")->fetchColumn());
        try {
            $this->store->reserveRefund($payment['reference'], 'after-loss', 1);
            self::fail('Lost dispute ignored');
        } catch (PaymentError $e) {
            self::assertSame('dispute_requires_review', $e->reason);
        }
    }

    public function test_dispute_defers_delivery_without_initiating_compensation_and_win_allows_retry(): void
    {
        $this->remote = fn (CreatePayment $r) => $this->payment($r->reference, PaymentStatus::Captured);
        $payment = $this->start();
        $reader = $this->createMock(DisputeEvidenceReader::class);
        $reader->method('read')->willReturnOnConsecutiveCalls(new DisputeEvidence('d', 'ORD-test', new Money(12000, 'ARS'), 'opened'), new DisputeEvidence('d', 'ORD-test', new Money(12000, 'ARS'), 'won'));
        $service = new DisputeCoordinator($this->store, $this->accounts, $reader);
        $service->observe($payment['reference'], 'd');
        $product = $this->createMock(Fulfillment::class);
        $product->expects(self::once())->method('deliver')->willReturn(true);
        $product->expects(self::never())->method('compensate');
        $consumer = $this->createMock(FinancialEventConsumer::class);
        $consumer->expects(self::exactly(2))->method('consume')->with(self::isType('string'), 'payment.dispute_updated', self::isType('array'));
        $worker = new RecoveryWorker($this->store, $this->coordinator, $this->accounts, $product, $consumer);
        self::assertSame(['delivered' => 1,'failed' => 1], $worker->outbox());
        self::assertSame('pending', $this->store->intent($payment['reference'])['fulfillment']);
        $service->observe($payment['reference'], 'd');
        $this->db->exec("UPDATE outbox SET next_due=clock_timestamp()-interval '1 second'");
        self::assertSame(['delivered' => 2,'failed' => 0], $worker->outbox());
        self::assertSame('delivered', $this->store->intent($payment['reference'])['fulfillment']);
    }

    public function test_dispute_evidence_conflicts_and_outbox_failure_roll_back_without_losing_capture(): void
    {
        $this->remote = fn (CreatePayment $r) => $this->payment($r->reference, PaymentStatus::Captured);
        $payment = $this->start();
        $evidence = new DisputeEvidence('d', 'ORD-test', new Money(2000, 'ARS'), 'opened');
        $reader = $this->createMock(DisputeEvidenceReader::class);
        $reader->method('read')->willReturnCallback(fn () => $evidence);
        $service = new DisputeCoordinator($this->store, $this->accounts, $reader);
        $this->db->exec("ALTER TABLE outbox ADD CONSTRAINT reject_disputes CHECK(type<>'payment.dispute_updated')");
        try {
            $service->observe($payment['reference'], 'd');
            self::fail('Expected outbox failure');
        } catch (\PDOException $e) {
            self::assertSame('23514', $e->getCode());
        }
        self::assertSame(0, (int)$this->db->query("SELECT count(*) FROM transactions WHERE kind LIKE 'dispute_%'")->fetchColumn());
        self::assertSame(0, (int)$this->db->query("SELECT count(*) FROM operations WHERE kind='dispute'")->fetchColumn());
        self::assertSame('captured', $this->store->intent($payment['reference'])['state']);
        $this->db->exec('ALTER TABLE outbox DROP CONSTRAINT reject_disputes');
        self::assertTrue($service->observe($payment['reference'], 'd'));
        try {
            $this->store->recordDispute($this->store->intent($payment['reference']), new DisputeEvidence('d', 'ORD-test', new Money(3000, 'ARS'), 'won'));
            self::fail('Amount drift');
        } catch (PaymentError $e) {
            self::assertSame('dispute_evidence_invalid', $e->reason);
        }
        try {
            $this->store->recordDispute($this->store->intent($payment['reference']), new DisputeEvidence('overlap', 'ORD-test', new Money(11000, 'ARS'), 'opened'));
            self::fail('Excessive pending dispute');
        } catch (PaymentError $e) {
            self::assertSame('dispute_balance_mismatch', $e->reason);
        }
        self::assertSame('2000', $this->store->intent($payment['reference'])['disputed_minor']);
        self::assertSame(1, (int)$this->db->query("SELECT count(*) FROM transactions WHERE kind LIKE 'dispute_%'")->fetchColumn());
    }

    public function test_dispute_reader_rejects_mismatched_resources_and_stale_read_before_mutation(): void
    {
        $this->remote = fn (CreatePayment $r) => $this->payment($r->reference, PaymentStatus::Captured);
        $payment = $this->start();
        $reader = $this->createMock(DisputeEvidenceReader::class);
        $reader->method('read')->willReturnOnConsecutiveCalls(null, new DisputeEvidence('wrong', 'ORD-test', new Money(2000, 'ARS'), 'opened'), new DisputeEvidence('d', 'other', new Money(2000, 'ARS'), 'opened'), new DisputeEvidence('d', 'ORD-test', new Money(2000, 'USD'), 'opened'));
        $service = new DisputeCoordinator($this->store, $this->accounts, $reader);
        self::assertFalse($service->observe($payment['reference'], 'd'));
        for ($i = 0;$i < 3;$i++) {
            try {
                $service->observe($payment['reference'], 'd');
                self::fail('Mismatch');
            } catch (PaymentError $e) {
                self::assertSame('dispute_evidence_invalid', $e->reason);
            }
        }
        $stale = $this->store->intent($payment['reference']);
        $this->store->finishFulfillment($payment['reference'], 'delivered');
        self::assertFalse($this->store->recordDispute($stale, new DisputeEvidence('d', 'ORD-test', new Money(2000, 'ARS'), 'opened')));
        self::assertSame(0, (int)$this->db->query("SELECT count(*) FROM transactions WHERE kind LIKE 'dispute_%'")->fetchColumn());
    }

    public function test_financial_events_have_closed_payloads_and_cannot_store_provider_bodies(): void
    {
        $payment = $this->start();
        $payload = ['version' => 1,'reference' => $payment['reference'],'subject' => 'invoice:uuid-example','minor' => '12000','currency' => 'ARS'];
        foreach ([$payload + ['raw' => ['buyer_email' => 'private@example.test']],array_replace($payload, ['minor' => '9223372036854775808']),array_replace($payload, ['minor' => 12000]),array_replace($payload, ['currency' => 'invalid'])] as $invalid) {
            try {
                $this->store->recordEvent(CanonicalJson::uuid(), 'payment.captured', $invalid);
                self::fail('Open event schema');
            } catch (PaymentError $e) {
                self::assertSame('invalid_event', $e->reason);
            }
        }
        self::assertSame(0, (int)$this->db->query('SELECT count(*) FROM outbox')->fetchColumn());
    }

    public function test_precocious_webhook_is_durable_and_recovers_without_enqueue(): void
    {
        $payment = $this->start();
        $event = new \Ronu\PaymentGateways\WebhookEvent('mercadopago', 'notification-1', 'order.updated', 'order', 'ORD-test', 'ORD-test', ['secret' => 'not-persisted']);
        $this->gateway->method('parseWebhook')->willReturn($event);
        $this->gateway->method('paymentIdForEvent')->willReturn('ORD-test');
        $this->gateway->method('getPayment')->willReturn($this->payment($payment['reference'], PaymentStatus::Captured));
        $this->store->receiveWebhook($this->account(), [], 'bytes');
        $this->store->receiveWebhook($this->account(), [], 'bytes');
        self::assertSame(1, (int) $this->db->query('SELECT count(*) FROM inbox')->fetchColumn());
        self::assertStringNotContainsString('not-persisted', json_encode($this->db->query('SELECT * FROM inbox')->fetchAll(PDO::FETCH_ASSOC)));
        $worker = new RecoveryWorker($this->store, $this->coordinator, $this->accounts, $this->createMock(Fulfillment::class));
        self::assertSame(['processed' => 1,'deferred' => 0], $worker->inbox());
        self::assertSame(['processed' => 0,'deferred' => 0], $worker->inbox());
        self::assertSame('captured', $this->store->intent($payment['reference'])['state']);
        self::assertSame(1, $this->posts);
    }

    public function test_inbox_captures_local_version_before_remote_io_and_defers_a_stale_response(): void
    {
        $payment = $this->start();
        $event = new \Ronu\PaymentGateways\WebhookEvent('mercadopago', 'stale-event', 'order.updated', 'order', 'ORD-test', 'ORD-test', []);
        $this->gateway->method('parseWebhook')->willReturn($event);
        $this->gateway->method('paymentIdForEvent')->willReturn('ORD-test');
        $this->store->receiveWebhook($this->account(), [], 'bytes');
        $io = [];
        $this->gateway->expects(self::once())->method('getPayment')->willReturnCallback(function () use ($payment, &$io) {
            $io[] = $this->db->inTransaction();
            $this->store->transaction(function () use ($payment) {
                $intent = $this->store->intent($payment['reference'], true);
                $version = $intent['version'];
                $intent['version']++;
                $intent['next_action'] = ['type' => 'redirect','url' => 'http://127.0.0.1:55455/newer','returnsToMerchant' => true];
                $this->store->saveIntent($intent, $version);
            });
            return $this->payment($payment['reference'], PaymentStatus::RequiresAction);
        });
        $worker = new RecoveryWorker($this->store, $this->coordinator, $this->accounts, $this->createMock(Fulfillment::class));
        self::assertSame(['processed' => 0,'deferred' => 1], $worker->inbox());
        self::assertSame([false], $io);
        self::assertSame('http://127.0.0.1:55455/newer', $this->store->intent($payment['reference'])['next_action']['url']);
        self::assertSame('FAILED', $this->db->query('SELECT state FROM inbox')->fetchColumn());
    }

    public function test_unknown_create_webhook_first_lookup_resolves_identity_but_cannot_apply_stale_financial_state(): void
    {
        $this->remote = fn () => throw new \RuntimeException('response lost');
        $payment = $this->start();
        self::assertSame('UNKNOWN', $payment['operationState']);
        $event = new \Ronu\PaymentGateways\WebhookEvent('mercadopago', 'early-event', 'order.updated', 'order', 'ORD-test', 'ORD-test', []);
        $this->gateway->method('parseWebhook')->willReturn($event);
        $this->gateway->method('paymentIdForEvent')->willReturn('ORD-test');
        $this->store->receiveWebhook($this->account(), [], 'bytes');
        $this->gateway->expects(self::exactly(2))->method('getPayment')->willReturnOnConsecutiveCalls($this->payment($payment['reference'], PaymentStatus::Captured), $this->payment($payment['reference'], PaymentStatus::RequiresAction));
        $worker = new RecoveryWorker($this->store, $this->coordinator, $this->accounts, $this->createMock(Fulfillment::class));
        self::assertSame(['processed' => 1,'deferred' => 0], $worker->inbox());
        self::assertSame('requires_action', $this->store->intent($payment['reference'])['state']);
        self::assertSame('CONFIRMED', $this->store->operation($payment['operationReference'])['state']);
        self::assertSame(0, (int)$this->db->query('SELECT count(*) FROM transactions')->fetchColumn());
        self::assertSame(0, (int)$this->db->query('SELECT count(*) FROM outbox')->fetchColumn());
        self::assertSame(1, $this->posts);
    }

    public function test_invalid_signature_and_oversized_body_cannot_persist_inbox(): void
    {
        $this->gateway->method('parseWebhook')->willThrowException(new \Ronu\PaymentGateways\Exceptions\InvalidWebhookSignature('invalid'));
        foreach (['invalid bytes', str_repeat('x', 262145)] as $body) {
            try {
                $this->store->receiveWebhook($this->account(), [], $body);
                self::fail();
            } catch (\Ronu\PaymentGateways\Exceptions\InvalidWebhookSignature|PaymentError) {
            }
        }
        self::assertSame(0, (int) $this->db->query('SELECT count(*) FROM inbox')->fetchColumn());
    }

    public function test_unknown_event_version_goes_to_retry_without_instantiating_payload_class(): void
    {
        $this->remote = fn (CreatePayment $request) => $this->payment($request->reference, PaymentStatus::Captured);
        $this->start();
        $this->db->exec("UPDATE outbox SET version=999,type='RuntimeException'");
        $product = $this->createMock(Fulfillment::class);
        $product->expects(self::never())->method('deliver');
        $worker = new RecoveryWorker($this->store, $this->coordinator, $this->accounts, $product);
        self::assertSame(['delivered' => 0,'failed' => 1], $worker->outbox());
        self::assertSame(0, (int) $this->db->query('SELECT count(*) FROM outbox WHERE delivered_at IS NOT NULL')->fetchColumn());
    }

    public function test_reconciliation_advances_over_205_intents_two_accounts_and_remote_failures(): void
    {
        $first = $this->start();
        $base = $this->store->intent($first['reference']);
        $operation = $this->store->operation($first['operationReference']);
        $this->accounts->accounts['b'] = new Account('b', 'merchant-b', 'emulator', 'ARS', 1, $this->gateway, ['http://127.0.0.1:55455'], false);
        $resources = ['ORD-test' => $base];
        for ($i = 1; $i < 205; $i++) {
            $intent = $base;
            $intent['reference'] = CanonicalJson::uuid();
            $intent['subject'] = 'invoice:'.$i;
            $intent['account_alias'] = $i % 2 ? 'a' : 'b';
            $intent['account_identity'] = $this->accounts->accounts[$intent['account_alias']]->identity();
            $intent['provider_id'] = 'ORD-'.$i;
            $op = $operation;
            $op['reference'] = CanonicalJson::uuid();
            $op['intent_reference'] = $intent['reference'];
            $op['key'] = 'batch-request-'.$i;
            $op['account_alias'] = $intent['account_alias'];
            $this->store->transaction(function () use ($intent, $op): void {
                $this->store->insertIntent($intent);
                $this->store->insertOperation($op);
            });
            $resources[$intent['provider_id']] = $intent;
        }
        $reads = 0;
        $remoteInsideTransaction = false;
        $this->gateway->method('getPayment')->willReturnCallback(function (string $id) use ($resources, &$reads, &$remoteInsideTransaction): ?Payment {
            $reads++;
            $remoteInsideTransaction = $remoteInsideTransaction || $this->db->inTransaction();
            if ($id === 'ORD-3') {
                return null;
            }
            if ($id === 'ORD-4') {
                throw new \RuntimeException('503 simulated');
            }
            return new Payment('mercadopago', $id, PaymentStatus::Captured, new Money(12000, 'ARS'), $resources[$id]['reference'], 'captured', transactionId:'TX-'.$id, capturedAmount:new Money(12000, 'ARS'));
        });
        $worker = new RecoveryWorker($this->store, $this->coordinator, $this->accounts, $this->createMock(Fulfillment::class));
        $started = microtime(true);
        $one = $worker->reconcile(200);
        $two = $worker->reconcile(200);
        self::assertSame(200, array_sum($one));
        self::assertSame(5, array_sum($two));
        self::assertSame(203, $one['processed'] + $two['processed']);
        self::assertSame(2, $one['deferred'] + $two['deferred']);
        self::assertSame(205, $reads);
        self::assertSame(1, $this->posts);
        self::assertFalse($remoteInsideTransaction);
        self::assertLessThan(60, microtime(true) - $started);
        self::assertSame(203, (int)$this->db->query('SELECT count(*) FROM transactions')->fetchColumn());
        self::assertSame(['pending' => 2,'exhausted' => 0], $this->store->queueStatus()['intents']);
    }

    public function test_worker_recovers_terminal_payment_refunds_without_posts_and_preserves_unknown_sibling(): void
    {
        $this->remote = fn (CreatePayment $request) => $this->payment($request->reference, PaymentStatus::Captured);
        $first = $this->start();
        $known = $this->store->reserveRefund($first['reference'], 'known', 1000);
        $unknown = $this->store->reserveRefund($first['reference'], 'unknown', 1000);
        $prepared = $this->store->reserveRefund($first['reference'], 'prepared', 1000);
        $this->store->claimRefund($known['reference']);
        $this->store->observePendingRefund($known['reference'], 'refund-known');
        $this->store->claimRefund($unknown['reference']);
        $this->store->markRefundUncertain($unknown['reference']);
        $this->gateway->expects(self::never())->method('refund');
        $this->gateway->expects(self::never())->method('getPayment');
        $reader = $this->createMock(RefundEvidenceReader::class);
        $reads = [];
        $reader->method('read')->willReturnCallback(function (Account $account, string $id, ?string $refundId, string $key) use (&$reads): ?Refund {
            self::assertFalse($this->db->inTransaction());
            $reads[] = $refundId;
            return $refundId === null ? null : new Refund($refundId, new Money(1000, 'ARS'), 'processed', $id);
        });
        $worker = new RecoveryWorker($this->store, $this->coordinator, $this->accounts, $this->createMock(Fulfillment::class), refunds: new RefundCoordinator($this->store, $this->accounts, $reader, false));
        self::assertSame(['processed' => 0, 'deferred' => 1], $worker->reconcile());
        self::assertCount(2, $reads);
        self::assertSame('COMPLETED', $this->store->refundOperation($known['reference'])['state']);
        self::assertSame('UNKNOWN', $this->store->refundOperation($unknown['reference'])['state']);
        self::assertSame('PREPARED', $this->store->refundOperation($prepared['reference'])['state']);
        self::assertSame('1000', $this->store->intent($first['reference'])['refunded_minor']);
        self::assertSame(['pending' => 1, 'exhausted' => 0], $this->store->queueStatus()['intents']);
        self::assertSame(['processed' => 0, 'deferred' => 0], $worker->reconcile());
        $this->store->observePendingRefund($unknown['reference'], 'refund-later');
        $this->db->exec("UPDATE reconciliation SET reconcile_due=clock_timestamp()-interval '1 second'");
        self::assertSame(['processed' => 1, 'deferred' => 0], $worker->reconcile());
        self::assertSame('2000', $this->store->intent($first['reference'])['refunded_minor']);
        self::assertSame(['pending' => 0, 'exhausted' => 0], $this->store->queueStatus()['intents']);
        self::assertSame(1, $this->posts);
    }

    public function test_retired_refunds_allow_historical_replay_and_compensation_ack_but_not_new_writes(): void
    {
        $this->remote = fn (CreatePayment $request) => $this->payment($request->reference, PaymentStatus::Captured);
        $first = $this->start();
        $this->store->finishFulfillment($first['reference'], 'compensation_required');
        $key = 'compensation:v1:'.$first['reference'];
        $refund = $this->store->reserveRefund($first['reference'], $key, 12000);
        $this->store->claimRefund($refund['reference']);
        $this->store->confirmRefund($refund['reference'], 'refund-compensation', new Money(12000, 'ARS'));
        $retired = new RefundCoordinator($this->store, $this->accounts, $this->createMock(RefundEvidenceReader::class), false);
        $this->gateway->expects(self::never())->method('refund');
        self::assertSame(['operationReference' => $refund['reference'], 'operationState' => 'COMPLETED'], $retired->requestCompensation($first['reference']));
        $actor = new Actor('invoices', 'tenant-a', 'buyer-a', ['a'], ['refund', 'read']);
        try {
            $retired->request($actor, $first['reference'], new Money(1000, 'ARS'), 'new-request');
            self::fail('New refund accepted after retirement');
        } catch (PaymentError $error) {
            self::assertSame('refunds_disabled', $error->reason);
        }
        self::assertSame(1, (int)$this->db->query("SELECT count(*) FROM transactions WHERE kind='refund'")->fetchColumn());
    }

    public function test_refund_recovery_without_reader_exhausts_visibly_and_new_work_rearms_queue(): void
    {
        $this->remote = fn (CreatePayment $request) => $this->payment($request->reference, PaymentStatus::Captured);
        $first = $this->start();
        $refund = $this->store->reserveRefund($first['reference'], 'unknown', 1000);
        $this->store->claimRefund($refund['reference']);
        $this->gateway->expects(self::never())->method('refund');
        $this->gateway->expects(self::never())->method('getPayment');
        $worker = new RecoveryWorker($this->store, $this->coordinator, $this->accounts, $this->createMock(Fulfillment::class));
        for ($i = 0; $i < 8; $i++) {
            $this->db->exec("UPDATE reconciliation SET reconcile_due=clock_timestamp()-interval '1 second'");
            self::assertSame(['processed' => 0, 'deferred' => 1], $worker->reconcile());
        }
        self::assertSame(['pending' => 1, 'exhausted' => 1], $this->store->queueStatus()['intents']);
        self::assertSame(['processed' => 0, 'deferred' => 0], $worker->reconcile());
        self::assertSame('SENDING', $this->store->refundOperation($refund['reference'])['state']);
        $this->store->observePendingRefund($refund['reference'], 'new-correlation');
        self::assertSame(0, (int)$this->db->query('SELECT reconcile_attempts FROM reconciliation')->fetchColumn());
        self::assertSame(['processed' => 0, 'deferred' => 1], $worker->reconcile());
        $this->store->observePendingRefund($refund['reference'], 'new-correlation');
        self::assertSame(1, (int)$this->db->query('SELECT reconcile_attempts FROM reconciliation')->fetchColumn());
        $this->db->exec('UPDATE reconciliation SET reconcile_attempts=8');
        $next = $this->store->reserveRefund($first['reference'], 'new-operation', 1000);
        $this->store->claimRefund($next['reference']);
        self::assertSame(['pending' => 1, 'exhausted' => 0], $this->store->queueStatus()['intents']);
        self::assertSame(['processed' => 0, 'deferred' => 1], $worker->reconcile());
        self::assertSame(1, $this->posts);
    }

    public function test_legacy_terminal_refund_creates_checkpoint_and_rearming_preserves_worker_lease(): void
    {
        $this->remote = fn (CreatePayment $request) => $this->payment($request->reference, PaymentStatus::Captured);
        $first = $this->start();
        $this->db->exec('DELETE FROM reconciliation');
        $refund = $this->store->reserveRefund($first['reference'], 'after-adoption', 1000);
        $this->store->claimRefund($refund['reference']);
        $claim = $this->store->claimReconciliation();
        self::assertSame($first['reference'], $claim['reference']);
        $this->store->observePendingRefund($refund['reference'], 'correlated');
        self::assertNull($this->store->claimReconciliation());
        self::assertSame($claim['token'], $this->db->query('SELECT reconcile_token FROM reconciliation')->fetchColumn());
        self::assertTrue($this->store->finishReconciliation($claim['reference'], $claim['token'], false));
        // Old imported uncertain rows can also acquire their first checkpoint on new evidence.
        $this->db->exec('DELETE FROM reconciliation');
        $second = $this->store->reserveRefund($first['reference'], 'uncorrelated-import', 1000);
        $this->store->claimRefund($second['reference']);
        $this->db->exec('DELETE FROM reconciliation');
        $this->store->observePendingRefund($second['reference'], 'new-correlation');
        self::assertSame(1, (int)$this->db->query('SELECT count(*) FROM reconciliation')->fetchColumn());
    }

    public function test_reconciliation_unknown_resource_exhausts_visibly_without_resending_and_lease_is_owned(): void
    {
        $this->remote = function (): Payment {
            throw new \RuntimeException('response lost');
        };
        $first = $this->start();
        $claim = $this->store->claimReconciliation();
        self::assertNull($this->store->claimReconciliation());
        self::assertFalse($this->store->finishReconciliation($claim['reference'], CanonicalJson::uuid(), true));
        $this->db->exec("UPDATE reconciliation SET reconcile_until=clock_timestamp()-interval '1 second'");
        $new = $this->store->claimReconciliation();
        self::assertNotSame($claim['token'], $new['token']);
        self::assertFalse($this->store->finishReconciliation($claim['reference'], $claim['token'], true));
        self::assertTrue($this->store->finishReconciliation($new['reference'], $new['token'], false));
        $worker = new RecoveryWorker($this->store, $this->coordinator, $this->accounts, $this->createMock(Fulfillment::class));
        for ($i = 0;$i < 6;$i++) {
            $this->db->exec("UPDATE reconciliation SET reconcile_due=clock_timestamp()-interval '1 second'");
            self::assertSame(['processed' => 0,'deferred' => 1], $worker->reconcile());
        }
        self::assertSame(['pending' => 1,'exhausted' => 1], $this->store->queueStatus()['intents']);
        self::assertSame(['processed' => 0,'deferred' => 0], $worker->reconcile());
        self::assertSame('UNKNOWN', $this->coordinator->operationView($this->actor, $first['operationReference'])['operationState']);
        self::assertSame(1, $this->posts);
    }

    private function replayFails(string $reason, callable $call): void
    {
        try {
            $call();
            self::fail('Replay accepted: expected '.$reason);
        } catch (PaymentError $error) {
            self::assertSame($reason, $error->reason);
        }
    }

    private function replayAudits(): int
    {
        return (int) $this->db->query('SELECT count(*) FROM replays')->fetchColumn();
    }

    public function test_replay_fails_closed_without_audit_table_and_never_touches_the_item(): void
    {
        $this->remote = function (): Payment {
            throw new \RuntimeException('response lost');
        };
        $first = $this->start();
        $this->db->exec('UPDATE reconciliation SET reconcile_attempts=8');
        $mapping = $this->mapping;
        unset($mapping['replays']);
        $legacy = new PostgresStore($this->db, $mapping);
        $this->replayFails('replay_audit_unavailable', fn () => $legacy->replayExhausted('intents', $first['reference'], 'operator-1', 'ticket:42', 'replay-1'));
        self::assertSame(8, (int) $this->db->query('SELECT reconcile_attempts FROM reconciliation')->fetchColumn());
        self::assertNull($legacy->metrics()['replays']);
        // The exhausted gauge and its alert remain available to a consumer without the upgrade.
        self::assertSame(['exhausted'], array_column($legacy->alerts(), 'condition'));
    }

    public function test_exhausted_reconciliation_is_listed_alerted_and_replayed_once_with_audit(): void
    {
        $this->remote = function (): Payment {
            throw new \RuntimeException('response lost');
        };
        $first = $this->start();
        $worker = new RecoveryWorker($this->store, $this->coordinator, $this->accounts, $this->createMock(Fulfillment::class));
        self::assertSame([], $this->store->alerts());
        self::assertSame([], $this->store->exhaustedItems('intents'));
        for ($i = 0; $i < 8; $i++) {
            $this->db->exec("UPDATE reconciliation SET reconcile_due=clock_timestamp()-interval '1 second'");
            self::assertSame(['processed' => 0, 'deferred' => 1], $worker->reconcile());
        }
        self::assertSame(['processed' => 0, 'deferred' => 0], $worker->reconcile());
        $items = $this->store->exhaustedItems('intents');
        self::assertCount(1, $items);
        self::assertSame(['queue' => 'intents', 'item' => $first['reference'], 'attempts' => 8, 'detail' => 'created'], array_diff_key($items[0], ['overdueSeconds' => true]));
        $alerts = $this->store->alerts();
        self::assertSame([['intents', 'exhausted', 1]], array_map(fn ($a) => [$a['queue'], $a['condition'], $a['count']], $alerts));
        self::assertStringContainsString('replay-exhausted --queue=intents', $alerts[0]['action']);
        self::assertSame(['pending' => 1, 'exhausted' => 1, 'stalled' => 0], array_diff_key($this->store->metrics()['intents'], ['oldestDueSeconds' => true]));
        // Single-item lookup reaches an item regardless of the page; absent or malformed is explicit.
        self::assertSame([$first['reference']], array_column($this->store->exhaustedItems('intents', 1, $first['reference']), 'item'));
        self::assertSame([], $this->store->exhaustedItems('intents', 200, CanonicalJson::uuid()));
        $this->replayFails('invalid_replay_request', fn () => $this->store->exhaustedItems('intents', 200, '1 OR 1=1'));

        $this->replayFails('invalid_replay_request', fn () => $this->store->replayExhausted('intents', $first['reference'], 'ana@example.com', 'ticket:42', 'replay-1'));
        $this->replayFails('invalid_replay_request', fn () => $this->store->replayExhausted('intents', '1', 'operator-1', 'ticket:42', 'replay-1'));
        $this->replayFails('invalid_queue', fn () => $this->store->replayExhausted('refunds', $first['reference'], 'operator-1', 'ticket:42', 'replay-1'));
        $this->replayFails('not_found', fn () => $this->store->replayExhausted('intents', CanonicalJson::uuid(), 'operator-1', 'ticket:42', 'replay-1'));
        $this->db->exec("UPDATE reconciliation SET reconcile_until=clock_timestamp()+interval '1 minute'");
        $this->replayFails('replay_lease_active', fn () => $this->store->replayExhausted('intents', $first['reference'], 'operator-1', 'ticket:42', 'replay-1'));
        $this->db->exec('UPDATE reconciliation SET reconcile_until=NULL');
        self::assertSame(0, $this->replayAudits());
        self::assertSame(8, (int) $this->db->query('SELECT reconcile_attempts FROM reconciliation')->fetchColumn());

        $replay = $this->store->replayExhausted('intents', $first['reference'], 'operator-1', 'ticket:42', 'replay-1');
        self::assertTrue($replay['replayed']);
        self::assertSame(8, $replay['attemptsBefore']);
        self::assertSame(1, $this->replayAudits());
        self::assertSame(['operator-1', 'ticket:42', 'intents', $first['reference'], 8], array_values($this->db->query('SELECT actor,evidence,queue,item,attempts_before FROM replays')->fetch(PDO::FETCH_ASSOC)));
        self::assertSame(0, (int) $this->db->query('SELECT reconcile_attempts FROM reconciliation')->fetchColumn());
        self::assertSame([], $this->store->alerts());
        // The item is claimable again, and the replay itself caused no provider write.
        self::assertSame(['processed' => 0, 'deferred' => 1], $worker->reconcile());
        self::assertSame(1, $this->posts);

        $again = $this->store->replayExhausted('intents', $first['reference'], 'operator-1', 'ticket:42', 'replay-1');
        self::assertFalse($again['replayed']);
        self::assertSame($replay['recordedAt'], $again['recordedAt']);
        self::assertSame(1, (int) $this->db->query('SELECT reconcile_attempts FROM reconciliation')->fetchColumn());
        $this->replayFails('replay_key_conflict', fn () => $this->store->replayExhausted('intents', $first['reference'], 'operator-1', 'ticket:43', 'replay-1'));
        $this->replayFails('replay_not_exhausted', fn () => $this->store->replayExhausted('intents', $first['reference'], 'operator-1', 'ticket:42', 'replay-2'));
        self::assertSame(1, $this->replayAudits());
    }

    public function test_exhausted_outbox_replay_redelivers_with_the_same_event_id(): void
    {
        $this->remote = fn (CreatePayment $request) => $this->payment($request->reference, PaymentStatus::Captured);
        $payment = $this->start();
        $product = new class () implements Fulfillment {
            public array $events = [];
            public bool $available = false;
            public function deliver(string $eventId, string $subject, array $payment): bool
            {
                $this->events[] = $eventId;
                if (!$this->available) {
                    throw new \RuntimeException('business store unavailable');
                }
                return true;
            }
            public function compensate(string $eventId, string $subject, array $payment): void
            {
                throw new \RuntimeException('unexpected');
            }
        };
        $worker = new RecoveryWorker($this->store, $this->coordinator, $this->accounts, $product);
        for ($i = 0; $i < 8; $i++) {
            $this->db->exec("UPDATE outbox SET next_due=clock_timestamp()-interval '1 second'");
            self::assertSame(['delivered' => 0, 'failed' => 1], $worker->outbox());
        }
        $this->db->exec("UPDATE outbox SET next_due=clock_timestamp()-interval '1 second'");
        self::assertSame(['delivered' => 0, 'failed' => 0], $worker->outbox());
        $event = $this->db->query('SELECT event_id FROM outbox')->fetchColumn();
        self::assertSame([['queue' => 'outbox', 'item' => $event, 'attempts' => 8, 'detail' => 'payment.captured']], array_map(fn ($i) => array_diff_key($i, ['overdueSeconds' => true]), $this->store->exhaustedItems('outbox')));
        $product->available = true;
        $replay = $this->store->replayExhausted('outbox', $event, 'operator-1', 'incident:7', 'outbox-replay-1');
        self::assertTrue($replay['replayed']);
        self::assertSame(['delivered' => 1, 'failed' => 0], $worker->outbox());
        self::assertSame(array_fill(0, 9, $event), $product->events);
        self::assertSame('delivered', $this->store->intent($payment['reference'])['fulfillment']);
        $this->replayFails('replay_not_pending', fn () => $this->store->replayExhausted('outbox', $event, 'operator-1', 'incident:7', 'outbox-replay-2'));
        self::assertSame(1, $this->replayAudits());
        self::assertSame(1, $this->posts);
    }

    public function test_exhausted_inbox_replay_and_stalled_alert_name_no_personal_data(): void
    {
        $payment = $this->start();
        $event = new \Ronu\PaymentGateways\WebhookEvent('mercadopago', 'notification-1', 'order.updated', 'order', 'ORD-test', 'ORD-test', ['payer' => 'buyer@example.com']);
        $this->gateway->method('parseWebhook')->willReturn($event);
        $this->gateway->method('paymentIdForEvent')->willReturn('ORD-test');
        $this->store->receiveWebhook($this->account(), [], 'bytes');
        // A due, retryable item nobody claims for longer than the threshold is a stalled worker.
        $this->db->exec("UPDATE inbox SET next_due=clock_timestamp()-interval '20 minutes'");
        self::assertSame([['inbox', 'stalled', 1]], array_map(fn ($a) => [$a['queue'], $a['condition'], $a['count']], $this->store->alerts()));
        self::assertSame([], $this->store->alerts(3600));
        $this->db->exec('UPDATE inbox SET attempts=8');
        $id = (string) $this->db->query('SELECT id FROM inbox')->fetchColumn();
        $listing = json_encode([$this->store->exhaustedItems('inbox'), $this->store->alerts(), $this->store->metrics()], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('buyer@example.com', $listing);
        self::assertStringNotContainsString('tenant-a', $listing);
        $replay = $this->store->replayExhausted('inbox', $id, 'operator-1', 'ticket:9', 'inbox-replay-1');
        self::assertTrue($replay['replayed']);
        $this->gateway->method('getPayment')->willReturn($this->payment($payment['reference'], PaymentStatus::Captured));
        $worker = new RecoveryWorker($this->store, $this->coordinator, $this->accounts, $this->createMock(Fulfillment::class));
        self::assertSame(['processed' => 1, 'deferred' => 0], $worker->inbox());
        self::assertSame('captured', $this->store->intent($payment['reference'])['state']);
        self::assertSame(1, $this->store->metrics()['replays']);
        self::assertSame(1, $this->posts);
    }
}
