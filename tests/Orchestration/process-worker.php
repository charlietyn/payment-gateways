<?php

declare(strict_types=1);

// Process fixture only. The parent owns the marked test database and its session lock.
require getenv('PAYMENT_PROCESS_AUTOLOAD') ?: dirname(__DIR__, 2).'/vendor/autoload.php';

use Ronu\PaymentGateways\Contracts\Gateway;
use Ronu\PaymentGateways\{Capability, CreatePayment, Money, Payment, PaymentStatus, Refund, WebhookEvent};
use Ronu\PaymentGateways\Orchestration\{Account, Actor, Coordinator, PaymentError, PostgresStore, Quote, RecoveryWorker, RefundCoordinator};
use Ronu\PaymentGateways\Orchestration\Contracts\{AccountResolver, Fulfillment, RefundEvidenceReader, SubjectResolver};

function checkpoint(string $name): void
{
    echo json_encode(['checkpoint' => $name, 'runtimeVersion' => Composer\InstalledVersions::getPrettyVersion('ronu/payment-gateways'), 'storeHash' => hash_file('sha256', (new ReflectionClass(PostgresStore::class))->getFileName())], JSON_THROW_ON_ERROR).PHP_EOL;
    flush();
    if (trim((string) fgets(STDIN)) !== 'continue') {
        throw new RuntimeException('Parent did not release checkpoint.');
    }
}

function connection(): PDO
{
    if (getenv('APP_ENV') !== 'testing' || getenv('PAYMENT_TEST_LOCK_HELD') !== '1'
        || getenv('PGHOST') !== '127.0.0.1' || getenv('PGDATABASE') !== 'payment_feature_invoices_testing'
        || getenv('PGUSER') !== 'payment_feature_owner' || !ctype_digit((string) getenv('PGPORT'))
        || (int) getenv('PGPORT') < 1024 || getenv('PGPORT') === '5432') {
        throw new RuntimeException('Unsafe process fixture environment.');
    }
    $schema = (string) getenv('PAYMENT_PROCESS_SCHEMA');
    if (!preg_match('/^payment_process_[a-f0-9]{16}$/D', $schema)) {
        throw new RuntimeException('Invalid process fixture schema.');
    }
    $pdo = new PDO('pgsql:host=127.0.0.1;port='.getenv('PGPORT').';dbname=payment_feature_invoices_testing', 'payment_feature_owner', (string) getenv('PGPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('SET search_path TO "'.$schema.'"');
    $pdo->exec("SET statement_timeout='10s'");
    return $pdo;
}

final class ProcessGateway implements Gateway
{
    public function __construct(private PDO $remote, private string $pause)
    {
    }

    public function name(): string
    {
        return 'mercadopago';
    }
    public function supports(Capability $capability): bool
    {
        return $capability === Capability::PartialRefund;
    }

    public function createPayment(CreatePayment $request): Payment
    {
        if ($this->pause === 'before_remote') {
            checkpoint('before_remote');
        }
        // A separate connection models a provider commit which cannot roll back with the caller.
        $query = $this->remote->prepare("INSERT INTO remote_writes(kind,request_key,reference,minor) VALUES ('create',?,?,?)");
        $query->execute([$request->idempotencyKey, $request->reference, $request->amount->minor]);
        if ($this->pause === 'after_remote') {
            checkpoint('after_remote');
        }
        return $this->getPayment('order:'.$request->reference);
    }

    public function getPayment(string $paymentId): ?Payment
    {
        $reference = substr($paymentId, strlen('order:'));
        $query = $this->remote->prepare("SELECT minor FROM remote_writes WHERE kind='create' AND reference=?");
        $query->execute([$reference]);
        $minor = $query->fetchColumn();
        if ($minor === false) {
            return null;
        }
        $amount = new Money((int) $minor, 'ARS');
        return new Payment('mercadopago', $paymentId, PaymentStatus::Captured, $amount, $reference, 'processed', transactionId: 'capture:'.$reference, capturedAmount: $amount);
    }

    public function refund(string $paymentId, ?Money $amount, string $idempotencyKey): Refund
    {
        $query = $this->remote->prepare("INSERT INTO remote_writes(kind,request_key,reference,minor) VALUES ('refund',?,?,?)");
        $query->execute([$idempotencyKey, $paymentId, $amount->minor]);
        if ($this->pause === 'after_remote') {
            checkpoint('after_remote');
        }
        return new Refund('refund:'.$idempotencyKey, $amount, 'completed', $paymentId);
    }

    public function capture(string $paymentId, ?Money $amount = null, ?string $idempotencyKey = null): Payment
    {
        throw new RuntimeException('Unexpected capture');
    }
    public function cancel(string $paymentId, ?string $idempotencyKey = null): Payment
    {
        throw new RuntimeException('Unexpected cancel');
    }
    public function parseWebhook(array $headers, string $rawBody): WebhookEvent
    {
        throw new RuntimeException('Signature verification belongs to emulator acceptance');
    }
    public function paymentIdForEvent(WebhookEvent $event): ?string
    {
        return $event->paymentId;
    }
}

try {
    $db = connection();
    $remote = connection();
    $input = json_decode((string) fgets(STDIN), true, 32, JSON_THROW_ON_ERROR);
    $mapping = [];
    foreach (['intents','operations','transactions','outbox','refunds','inbox','reconciliation'] as $key) {
        $mapping[$key] = ['schema' => getenv('PAYMENT_PROCESS_SCHEMA'), 'table' => $key];
    }
    $store = new PostgresStore($db, $mapping);
    $actor = new Actor('process-fixture', 'tenant', 'buyer', ['a'], ['create','read','refund']);
    $gateway = new ProcessGateway($remote, $input['pause'] ?? '');
    $account = new Account('a', 'merchant-process', 'emulator', 'ARS', 1, $gateway, [], true);
    $accounts = new class ($account) implements AccountResolver {
        public function __construct(private Account $account)
        {
        }
        public function forNew(Actor $actor, ?string $alias): Account
        {
            $actor->require('create', $alias ?? 'a');
            return $this->account;
        }
        public function historical(string $alias, string $identity): Account
        {
            if ($alias !== 'a' || $identity !== $this->account->identity()) {
                throw new PaymentError('account_identity_changed', 503);
            }
            return $this->account;
        }
    };
    $subjects = new class () implements SubjectResolver {
        public function resolve(Actor $actor, string $subject): Quote
        {
            return new Quote($subject, 'v1', $actor->scope(), new Money(12000, 'ARS'), time() + 600);
        }
    };
    $coordinator = new Coordinator($store, $subjects, $accounts, fn () => time());
    $reader = new class () implements RefundEvidenceReader {
        public function read(Account $account, string $paymentId, ?string $refundId, string $remoteKey): ?Refund
        {
            return null;
        }
    };
    $refunds = new RefundCoordinator($store, $accounts, $reader, true);
    $product = new class ($remote, $input['pause'] ?? '') implements Fulfillment {
        public function __construct(private PDO $db, private string $pause)
        {
        }
        public function deliver(string $eventId, string $subject, array $payment): bool
        {
            $q = $this->db->prepare('INSERT INTO product_receipts(event_id,subject) VALUES (?,?) ON CONFLICT(event_id) DO NOTHING');
            $q->execute([$eventId, $subject]);
            if ($this->pause === 'after_delivery') {
                checkpoint('after_delivery');
            }
            return true;
        }
        public function compensate(string $eventId, string $subject, array $payment): void
        {
            throw new RuntimeException('Unexpected compensation');
        }
    };
    $worker = new RecoveryWorker($store, $coordinator, $accounts, $product, refunds: $refunds);
    checkpoint('ready');
    if ($input['command'] === 'atomic-capture') {
        $result = $store->transaction(function () use ($store, $input): bool {
            $intent = $store->intent($input['reference'], true);
            $version = $intent['version'];
            $intent['state'] = 'captured';
            $intent['captured_minor'] = $intent['amount_minor'];
            $intent['version']++;
            $store->recordCapture($intent, 'capture:'.$intent['reference']);
            $store->recordEvent($input['event'], 'payment.captured', ['version' => 1, 'reference' => $intent['reference'], 'subject' => $intent['subject'], 'minor' => $intent['amount_minor'], 'currency' => $intent['currency']]);
            $store->saveIntent($intent, $version);
            $operation = $store->createOperationFor($intent['reference']);
            $operation['state'] = 'CONFIRMED';
            $store->saveOperation($operation);
            checkpoint('before_commit');
            return true;
        });
        echo json_encode(['result' => $result], JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }
    $result = match ($input['command']) {
        'checkout' => $coordinator->checkout($actor, $input['subject'], 'v1', $input['key']),
        'operation' => $coordinator->operationView($actor, $input['reference']),
        'recover' => $coordinator->recoverOperation($input['reference'], $gateway->getPayment('order:'.$input['intent']), $input['version']),
        'refund' => $refunds->request($actor, $input['reference'], new Money($input['minor'], 'ARS'), $input['key']),
        'outbox' => $worker->outbox(1),
        'inbox' => $worker->inbox(1),
        'reconcile' => $worker->reconcile(1),
        'claim-outbox' => $store->claimEvent((int) ($input['leaseSeconds'] ?? 1)),
        'claim-inbox' => $store->claimInbox((int) ($input['leaseSeconds'] ?? 1)),
        'claim-reconcile' => $store->claimReconciliation((int) ($input['leaseSeconds'] ?? 1)),
        'ack-outbox' => $store->acknowledgeEvent($input['reference'], $input['token']),
        'ack-inbox' => $store->finishInbox($input['reference'], $input['token'], true),
        'ack-reconcile' => $store->finishReconciliation($input['reference'], $input['token'], true),
        default => throw new RuntimeException('Unknown process command'),
    };
    echo json_encode(['result' => $result], JSON_THROW_ON_ERROR).PHP_EOL;
    if (($input['pause'] ?? '') === 'after_claim') {
        checkpoint('after_claim');
    }
} catch (PaymentError $error) {
    echo json_encode(['error' => $error->reason, 'status' => $error->httpStatus], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error).': process fixture failed'.PHP_EOL);
    exit(1);
}
