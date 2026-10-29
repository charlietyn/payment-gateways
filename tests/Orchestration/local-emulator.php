<?php

declare(strict_types=1);
require dirname(__DIR__, 2).'/vendor/autoload.php';

use Ronu\PaymentGateways\{GatewayFactory, Money};
use Ronu\PaymentGateways\Orchestration\{Account, Actor, Coordinator, PostgresStore, Quote, RecoveryWorker, RefundCoordinator};
use Ronu\PaymentGateways\Orchestration\Contracts\{AccountResolver, SubjectResolver, Fulfillment};

if (getenv('APP_ENV') !== 'testing' || getenv('PGHOST') !== '127.0.0.1' || getenv('PGUSER') !== 'payment_feature_owner' || getenv('PGDATABASE') !== 'payment_feature_invoices_testing' || getenv('PAYMENT_TEST_LOCK_HELD') !== '1') {
    throw new RuntimeException('Unsafe test destination.');
}
$api = (string) getenv('PAYMENT_EMULATOR_URL');
if (!preg_match('#^http://127\.0\.0\.1:[0-9]+$#D', $api)) {
    throw new RuntimeException('Only dedicated loopback emulator allowed.');
}
$db = new PDO('pgsql:host=127.0.0.1;port='.getenv('PGPORT').';dbname=payment_feature_invoices_testing', 'payment_feature_owner', (string) getenv('PGPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$role = $db->query('SELECT rolsuper,rolcreatedb,rolcreaterole FROM pg_roles WHERE rolname=current_user')->fetch(PDO::FETCH_ASSOC);
if ($role['rolsuper'] || $role['rolcreatedb'] || $role['rolcreaterole']) {
    throw new RuntimeException('Unsafe database role.');
}
$schema = 'payment_test_'.bin2hex(random_bytes(8));
$db->exec('CREATE SCHEMA '.$schema);
$db->exec('SET search_path TO '.$schema);
$db->exec(file_get_contents(__DIR__.'/schema.sql'));
$db->exec('CREATE TABLE invoice_receipts(event_id uuid PRIMARY KEY, subject text UNIQUE NOT NULL)');
$mapping = [];
foreach (['intents','operations','transactions','outbox','refunds','inbox','reconciliation'] as $table) {
    $mapping[$table] = ['schema' => $schema,'table' => $table];
}
$store = new PostgresStore($db, $mapping);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($socket === false) {
    throw new RuntimeException('Cannot allocate webhook port.');
}
$address = stream_socket_get_name($socket, false);
fclose($socket);
$sinkDir = sys_get_temp_dir().'/payment-feature-signed-'.bin2hex(random_bytes(8));
mkdir($sinkDir, 0700, true);
$router = $sinkDir.'/router.php';
file_put_contents($router, '<?php $record=["headers"=>getallheaders(),"body"=>file_get_contents("php://input")];file_put_contents(__DIR__."/".bin2hex(random_bytes(8)).".json",json_encode($record));http_response_code(200);echo "ok";');
$null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$sink = proc_open([PHP_BINARY,'-S',$address,$router], [0 => ['file',$null,'r'],1 => ['file',$null,'w'],2 => ['file',$null,'w']], $pipes);
if (!is_resource($sink)) {
    throw new RuntimeException('Cannot start webhook sink.');
}
try {
    $port = (int) substr(strrchr($address, ':'), 1);
    $ready = false;
    for ($i = 0;$i < 40;$i++) {
        $probe = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
        if ($probe) {
            fclose($probe);
            $ready = true;
            break;
        } usleep(100000);
    }
    if (!$ready) {
        throw new RuntimeException('Webhook listener failed.');
    }
    $control = new GuzzleHttp\Client(['base_uri' => $api,'timeout' => 10,'allow_redirects' => false,'headers' => ['Authorization' => 'Bearer '.getenv('PAYMENT_EMULATOR_ADMIN_TOKEN')]]);
    $json = fn ($response) => json_decode((string)$response->getBody(), true, 64, JSON_THROW_ON_ERROR);
    $merchant = $json($control->post('/users', ['json' => ['email' => 'payment-feature-'.bin2hex(random_bytes(8)).'@example.test','displayName' => 'isolated invoice acceptance','role' => 'merchant','password' => bin2hex(random_bytes(24))]]));
    $secret = bin2hex(random_bytes(32));
    $application = $json($control->post('/applications', ['json' => ['name' => 'payment-feature-invoices-'.bin2hex(random_bytes(5)),'provider' => 'plaza','ownerId' => $merchant['id'],'scenario' => 'plaza/payment-pending','webhookUrl' => 'http://'.$address.'/plaza','webhookSecret' => $secret,'expiresInSeconds' => 3600]]));
    $gateway = GatewayFactory::make('plaza', ['credentials' => ['access_token' => $application['credentials']['accessToken']],'webhooks' => ['secret' => $secret],'emulator' => ['api_url' => $api,'checkout_url' => 'http://127.0.0.1:55455'],'http' => ['retries' => 0]]);
    $account = new Account('mp-ar', (string)$merchant['id'], 'emulator', 'ARS', 1, $gateway, ['http://127.0.0.1:55455'], true);
    $actor = new Actor('invoices', 'test-tenant', 'buyer-uuid', ['mp-ar'], ['create','read']);
    $subjects = new class ($actor) implements SubjectResolver {
        public function __construct(private Actor $actor)
        {
        }
        public function resolve(Actor $actor, string $subject): Quote
        {
            return new Quote($subject, 'quote-v1', $this->actor->scope(), Money::fromDecimal('120.00', 'ARS'), time() + 300);
        }
    };
    $accounts = new class ($account) implements AccountResolver {
        public function __construct(private Account $account)
        {
        }
        public function forNew(Actor $actor, ?string $alias): Account
        {
            $actor->require('create', $alias ?? $this->account->alias);
            return $this->account;
        }
        public function historical(string $alias, string $identity): Account
        {
            if ($alias !== $this->account->alias || $identity !== $this->account->identity()) {
                throw new RuntimeException('Account mismatch');
            }return $this->account;
        }
    };
    $coordinator = new Coordinator($store, $subjects, $accounts, fn () => time());
    $checkout = $coordinator->checkout($actor, 'invoice-'.bin2hex(random_bytes(8)), 'quote-v1', 'request-1');
    if ($checkout['operationState'] !== 'CONFIRMED' || $checkout['financialState'] !== 'requires_action') {
        throw new RuntimeException('Create did not persist expected pending payment.');
    }
    $intent = $store->intent($checkout['reference']);
    if ($intent['next_action']['returnsToMerchant'] !== false) {
        throw new RuntimeException('Ticket falsely claims a merchant return.');
    }
    // The buyer's control call has no administrative/provider credential.
    $buyer = new GuzzleHttp\Client(['base_uri' => $api,'timeout' => 10,'allow_redirects' => false]);
    $buyer->post('/checkout/plaza/'.$intent['provider_id'].'/confirm', ['json' => ['method' => 'visa','email' => 'buyer@example.test']]);
    $verified = 0;
    $seen = [];
    $deadline = microtime(true) + 20;
    while (microtime(true) < $deadline && $verified < 1) {
        foreach (glob($sinkDir.'/*.json') ?: [] as $file) {
            if (isset($seen[$file])) {
                continue;
            }
            $record = json_decode(file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
            $event = $gateway->parseWebhook($record['headers'], $record['body']);
            if ($gateway->paymentIdForEvent($event) !== $intent['provider_id']) {
                continue;
            }
            $store->receiveWebhook($account, $record['headers'], $record['body']);
            $store->receiveWebhook($account, $record['headers'], $record['body']);
            $seen[$file] = true;
            $verified++;
        }
        if ($verified < 1) {
            usleep(100000);
        }
    }
    if ($verified < 1) {
        throw new RuntimeException('No signed webhook arrived from the real worker.');
    }
    $fulfillment = new class ($db) implements Fulfillment {
        public function __construct(private PDO $db)
        {
        }
        public function deliver(string $eventId, string $subject, array $payment): bool
        {
            $statement = $this->db->prepare('INSERT INTO invoice_receipts(event_id,subject) VALUES (?,?) ON CONFLICT(event_id) DO NOTHING');
            $statement->execute([$eventId,$subject]);
            return true;
        }
        public function compensate(string $eventId, string $subject, array $payment): void
        {
            throw new RuntimeException('Unexpected compensation');
        }
    };
    // Reconstruct after provider I/O, as a new worker would; nothing is kept in a request singleton.
    $worker = new RecoveryWorker($store, new Coordinator($store, $subjects, $accounts, fn () => time()), $accounts, $fulfillment);
    $inbox = $worker->inbox();
    $outbox = $worker->outbox();
    $again = $worker->outbox();
    $final = $coordinator->operationView($actor, $checkout['operationReference']);
    $transactions = (int)$db->query('SELECT count(*) FROM transactions')->fetchColumn();
    $receipts = (int)$db->query('SELECT count(*) FROM invoice_receipts')->fetchColumn();
    if ($final['financialState'] !== 'captured' || $final['fulfillmentState'] !== 'delivered' || $transactions !== 1 || $receipts !== 1 || $again['delivered'] !== 0) {
        throw new RuntimeException('Financial/fulfillment invariant failed.');
    }
    // An explicit local certification gate; no generated HTTP route enables refunds.
    $refundActor = new Actor('invoices', 'test-tenant', 'buyer-uuid', ['mp-ar'], ['refund','read']);
    $reader = new \Ronu\PaymentGateways\Orchestration\GatewayRefundEvidenceReader();
    $refunds = new RefundCoordinator($store, $accounts, $reader, true);
    $refund = $refunds->request($refundActor, $checkout['reference'], new Money(2000, 'ARS'), 'local-refund-1');
    if ($refund['operationState'] !== 'COMPLETED') {
        throw new RuntimeException('Local refund did not confirm individually.');
    }
    $replayed = $refunds->request($refundActor, $checkout['reference'], new Money(2000, 'ARS'), 'local-refund-1');
    if ($replayed !== $refund || (int)$db->query("SELECT count(*) FROM transactions WHERE kind='refund'")->fetchColumn() !== 1 || $store->intent($intent['reference'])['refunded_minor'] !== '2000') {
        throw new RuntimeException('Refund replay duplicated money.');
    }
    // Resume a stored individual ID with an uncertain local outcome. Read-only
    // recovery works even when accepting new refund commands has been disabled.
    $pending = $store->reserveRefund($intent['reference'], 'recovery-refund-2', 1000);
    $store->claimRefund($pending['reference']);
    $remoteRefund = $account->gateway->refund($intent['provider_id'], new Money(1000, 'ARS'), $pending['remote_key']);
    $store->observePendingRefund($pending['reference'], $remoteRefund->id);
    $store->markRefundUncertain($pending['reference']);
    $recovery = new RefundCoordinator(new PostgresStore($db, $mapping), $accounts, $reader, false);
    $refundWorker = new RecoveryWorker($store, $coordinator, $accounts, $fulfillment, refunds: $recovery);
    $refundRecovery = $refundWorker->reconcile();
    if ($refundRecovery !== ['processed' => 1, 'deferred' => 0] || !$recovery->recover($pending['reference']) ||
        (int)$db->query("SELECT count(*) FROM transactions WHERE kind='refund'")->fetchColumn() !== 2 ||
        $store->intent($intent['reference'])['refunded_minor'] !== '3000') {
        throw new RuntimeException('Individual refund GET recovery failed or duplicated money');
    }
    // A second invoice loses availability before delivery. The real local transport compensates it.
    $late = $coordinator->checkout($actor, 'unavailable-invoice-'.bin2hex(random_bytes(8)), 'quote-v1', 'late-request-1');
    $lateIntent = $store->intent($late['reference']);
    $buyer->post('/checkout/plaza/'.$lateIntent['provider_id'].'/confirm', ['json' => ['method' => 'visa','email' => 'buyer@example.test']]);
    if (!$coordinator->refresh($late['reference'])) {
        throw new RuntimeException('Late capture unavailable');
    }
    $unavailable = new class ($refunds) implements Fulfillment {
        public int $deliveries = 0;
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
            if ($this->refunds->requestCompensation($payment['reference'])['operationState'] !== 'COMPLETED') {
                throw new RuntimeException('Compensation unresolved');
            }
        }
    };
    $compensationWorker = new RecoveryWorker($store, $coordinator, $accounts, $unavailable);
    $compensationResult = $compensationWorker->outbox();
    $compensationReplay = $refunds->requestCompensation($late['reference']);
    $compensated = $coordinator->operationView($actor, $late['operationReference']);
    $ledgerCount = $db->prepare("SELECT count(*) FROM transactions WHERE intent_reference=? AND kind='refund'");
    $ledgerCount->execute([$late['reference']]);
    if ($compensated['financialState'] !== 'refunded' || $compensated['compensationState'] !== 'completed' || $compensated['fulfillmentState'] !== 'compensation_requested' || $compensationReplay['operationState'] !== 'COMPLETED' || (int)$ledgerCount->fetchColumn() !== 1 || $unavailable->deliveries !== 1 || $compensationWorker->outbox()['delivered'] !== 0) {
        throw new RuntimeException('Financial compensation invariant failed');
    }
    echo json_encode(['status' => 'PASS','applicationId' => $application['id'],'scenario' => 'plaza/payment-pending','paymentReference' => $checkout['reference'],'providerId' => $intent['provider_id'],'verifiedWebhooks' => $verified,'inbox' => $inbox,'outbox' => $outbox,'captureTransactions' => $transactions,'invoiceReceipts' => $receipts,'result' => $final,'localRefund' => $refund,'refundTransactions' => 2,'compensation' => $compensated,'compensationOutbox' => $compensationResult,'compensationRefundTransactions' => 1,'refundLookup' => 'PASS local known-ID GET recovery with new refunds disabled; missing-ID correlation remains unresolved'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
} finally {
    proc_terminate($sink);
    proc_close($sink);
    foreach (glob($sinkDir.'/*') ?: [] as $file) {
        unlink($file);
    }rmdir($sinkDir);
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $db->exec('DROP SCHEMA '.$schema.' CASCADE');
}
