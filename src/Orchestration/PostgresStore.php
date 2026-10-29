<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration;

use PDO;
use Ronu\PaymentGateways\Exceptions\InvalidWebhookSignature;
use Ronu\PaymentGateways\WebhookEvent;
use Ronu\PaymentGateways\Orchestration\Contracts\PaymentStore;

/** Tables are provisioned by the consumer's versioned schema, never by boot. */
final class PostgresStore implements PaymentStore
{
    private array $tables;

    /** @param array<string,array{schema:string,table:string}> $tables */
    public function __construct(private PDO $db, array $tables)
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            throw new PaymentError('unsupported_storage', 503);
        }
        // The manual-replay audit table is an additive upgrade: without it, replay fails closed.
        $keys = ['intents', 'operations', 'transactions', 'outbox', 'refunds', 'inbox', 'reconciliation'];
        if (array_key_exists('replays', $tables)) {
            $keys[] = 'replays';
        }
        foreach ($keys as $key) {
            $parts = $tables[$key] ?? [];
            foreach (['schema', 'table'] as $part) {
                if (!isset($parts[$part]) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/D', $parts[$part])) {
                    throw new PaymentError('invalid_storage_mapping', 503);
                }
            }
            $this->tables[$key] = '"'.$parts['schema'].'"."'.$parts['table'].'"';
        }
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function transaction(callable $work): mixed
    {
        if ($this->db->inTransaction()) {
            throw new PaymentError('nested_payment_transaction', 503);
        }
        $this->db->beginTransaction();
        try {
            $result = $work();
            $this->db->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function assertProviderIoAllowed(): void
    {
        if ($this->db->inTransaction()) {
            throw new PaymentError('provider_io_inside_transaction', 503);
        }
    }

    public function lock(string $scope, string $subject): void
    {
        if (!$this->db->inTransaction()) {
            throw new PaymentError('transaction_required', 503);
        }
        $this->query('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$scope.'/'.$subject]);
    }

    public function operationByKey(string $scope, string $key, ?string $alias): ?array
    {
        $sql = "SELECT data FROM {$this->tables['operations']} WHERE scope=? AND request_key=? AND kind='create'";
        $args = [$scope, $key];
        if ($alias !== null) {
            $sql .= ' AND account_alias=?';
            $args[] = $alias;
        }
        $rows = $this->query($sql, $args)->fetchAll(PDO::FETCH_COLUMN);
        if (count($rows) > 1) {
            throw new PaymentError('account_required_for_replay', 409);
        }
        return isset($rows[0]) ? json_decode($rows[0], true, 32, JSON_THROW_ON_ERROR) : null;
    }

    public function intentBySubject(string $scope, string $subject): ?array
    {
        return $this->one("SELECT data FROM {$this->tables['intents']} WHERE scope=? AND subject=?", [$scope, $subject]);
    }

    public function intentByProvider(string $accountIdentity, string $providerId): ?array
    {
        return $this->one("SELECT data FROM {$this->tables['intents']} WHERE data->>'account_identity'=? AND data->>'provider_id'=?", [$accountIdentity,$providerId]);
    }

    public function intent(string $reference, bool $lock = false): ?array
    {
        if ($lock && !$this->db->inTransaction()) {
            throw new PaymentError('transaction_required', 503);
        }
        return $this->one("SELECT data FROM {$this->tables['intents']} WHERE reference=?".($lock ? ' FOR UPDATE' : ''), [$reference]);
    }

    public function operation(string $reference): ?array
    {
        return $this->one("SELECT data FROM {$this->tables['operations']} WHERE reference=?", [$reference]);
    }

    public function createOperationFor(string $intentReference): ?array
    {
        return $this->one("SELECT data FROM {$this->tables['operations']} WHERE intent_reference=? AND kind='create'", [$intentReference]);
    }

    /** Known case IDs only. Never discover a dispute by matching aggregate money. */
    public function pendingDisputeIds(string $intentReference): array
    {
        return $this->query("SELECT data->>'provider_id' FROM {$this->tables['operations']} WHERE intent_reference=? AND kind='dispute' AND data->>'dispute_state'='opened' AND data->>'provider_id' IS NOT NULL ORDER BY reference", [$intentReference])->fetchAll(PDO::FETCH_COLUMN);
    }

    public function insertIntent(array $intent): void
    {
        $this->query("INSERT INTO {$this->tables['intents']} (reference,scope,subject,version,data) VALUES (?,?,?,?,?::jsonb)", [$intent['reference'], $intent['scope'], $intent['subject'], $intent['version'], CanonicalJson::encode($intent)]);
        $this->query("INSERT INTO {$this->tables['reconciliation']} (reference) VALUES (?)", [$intent['reference']]);
    }

    public function insertOperation(array $operation): void
    {
        $this->query("INSERT INTO {$this->tables['operations']} (reference,intent_reference,scope,account_alias,kind,request_key,data) VALUES (?,?,?,?,?,?,?::jsonb)", [$operation['reference'], $operation['intent_reference'], $operation['scope'], $operation['account_alias'], $operation['kind'], $operation['key'], CanonicalJson::encode($operation)]);
    }

    public function saveIntent(array $intent, int $expectedVersion): bool
    {
        return $this->query("UPDATE {$this->tables['intents']} SET version=?,data=?::jsonb WHERE reference=? AND version=?", [$intent['version'], CanonicalJson::encode($intent), $intent['reference'], $expectedVersion])->rowCount() === 1;
    }

    public function saveOperation(array $operation): void
    {
        if ($this->query("UPDATE {$this->tables['operations']} SET data=?::jsonb WHERE reference=?", [CanonicalJson::encode($operation), $operation['reference']])->rowCount() !== 1) {
            throw new PaymentError('missing_operation', 503);
        }
    }

    public function recordCapture(array $intent, string $remoteTransaction): void
    {
        $this->query("INSERT INTO {$this->tables['transactions']} (intent_reference,account_identity,remote_id,kind,minor,currency) VALUES (?,?,?,'capture',?,?) ON CONFLICT (account_identity,remote_id,kind) DO NOTHING", [$intent['reference'], $intent['account_identity'], $remoteTransaction, $intent['captured_minor'], $intent['currency']]);
        $row = $this->query("SELECT intent_reference,minor,currency FROM {$this->tables['transactions']} WHERE account_identity=? AND remote_id=? AND kind='capture'", [$intent['account_identity'], $remoteTransaction])->fetch(PDO::FETCH_ASSOC);
        if ($row['intent_reference'] !== $intent['reference'] || (string) $row['minor'] !== $intent['captured_minor'] || $row['currency'] !== $intent['currency']) {
            throw new PaymentError('ledger_conflict', 409);
        }
    }

    public function recordEvent(string $eventId, string $type, array $payload): void
    {
        if (!in_array($type, ['payment.captured','payment.refunded','payment.dispute_updated'], true) || ($payload['version'] ?? null) !== 1) {
            throw new PaymentError('unsupported_event', 503);
        }
        $keys = ['version','reference','subject','minor','currency'];
        if ($type !== 'payment.captured') {
            $keys = array_merge($keys, ['observationVersion','operationReference']);
        }
        if ($type === 'payment.dispute_updated') {
            $keys[] = 'state';
        }
        $actual = array_keys($payload);
        sort($actual);
        sort($keys);
        if ($actual !== $keys || !is_string($payload['subject']) || $payload['subject'] === '' || strlen($payload['subject']) > 128 || !is_string($payload['minor']) || !preg_match('/^[1-9][0-9]{0,18}$/D', $payload['minor']) || (strlen($payload['minor']) === 19 && strcmp($payload['minor'], '9223372036854775807') > 0) || !is_string($payload['currency']) || !preg_match('/^[A-Z]{3}$/D', $payload['currency'])) {
            throw new PaymentError('invalid_event', 503);
        }
        foreach ([$eventId,$payload['reference'],...($type === 'payment.captured' ? [] : [$payload['operationReference']])] as $id) {
            if (!is_string($id) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $id)) {
                throw new PaymentError('invalid_event', 503);
            }
        }
        if ($type !== 'payment.captured' && (!is_int($payload['observationVersion']) || $payload['observationVersion'] < 1)) {
            throw new PaymentError('invalid_event', 503);
        }
        if ($type === 'payment.dispute_updated' && !in_array($payload['state'], ['opened','won','lost'], true)) {
            throw new PaymentError('invalid_event', 503);
        }
        $this->query("INSERT INTO {$this->tables['outbox']} (event_id,intent_reference,type,version,payload) VALUES (?,?,?,1,?::jsonb)", [$eventId, $payload['reference'], $type, CanonicalJson::encode($payload)]);
    }

    /** Claim is committed before a consumer is called; no I/O under a row lock. */
    public function claimEvent(int $leaseSeconds = 60): ?array
    {
        if ($leaseSeconds < 1 || $leaseSeconds > 3600) {
            throw new PaymentError('invalid_lease', 422);
        }
        return $this->transaction(function () use ($leaseSeconds): ?array {
            $row = $this->query("SELECT * FROM {$this->tables['outbox']} WHERE delivered_at IS NULL AND attempts<8 AND next_due<=clock_timestamp() AND (leased_until IS NULL OR leased_until<=clock_timestamp()) ORDER BY next_due,event_id FOR UPDATE SKIP LOCKED LIMIT 1", [])->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            $token = CanonicalJson::uuid();
            $this->query("UPDATE {$this->tables['outbox']} SET lease_token=?,leased_until=clock_timestamp()+(? * interval '1 second'),attempts=attempts+1 WHERE event_id=?", [$token, $leaseSeconds, $row['event_id']]);
            return ['eventId' => $row['event_id'], 'type' => $row['type'], 'version' => (int) $row['version'], 'payload' => json_decode($row['payload'], true, 32, JSON_THROW_ON_ERROR), 'leaseToken' => $token];
        });
    }

    public function acknowledgeEvent(string $eventId, string $token): bool
    {
        return $this->query("UPDATE {$this->tables['outbox']} SET delivered_at=clock_timestamp(),lease_token=NULL,leased_until=NULL WHERE event_id=? AND lease_token=? AND leased_until>clock_timestamp() AND delivered_at IS NULL", [$eventId, $token])->rowCount() === 1;
    }

    public function failEvent(string $eventId, string $token): bool
    {
        return $this->query("UPDATE {$this->tables['outbox']} SET lease_token=NULL,leased_until=NULL,next_due=clock_timestamp()+(LEAST(300,5*power(2,attempts-1))*interval '1 second') WHERE event_id=? AND lease_token=? AND leased_until>clock_timestamp() AND delivered_at IS NULL", [$eventId, $token])->rowCount() === 1;
    }

    public function finishFulfillment(string $reference, string $state): void
    {
        if (!in_array($state, ['delivered', 'compensation_required', 'compensation_requested'], true)) {
            throw new PaymentError('invalid_fulfillment', 422);
        }
        $this->transaction(function () use ($reference, $state): void {
            $intent = $this->intent($reference, true) ?? throw new PaymentError('not_found', 404);
            if (in_array($intent['fulfillment'], ['delivered', 'compensation_requested'], true)) {
                if ($intent['fulfillment'] !== $state) {
                    throw new PaymentError('fulfillment_conflict', 409);
                }
                return;
            }
            if ($intent['captured_minor'] === '0') {
                throw new PaymentError('capture_required', 409);
            }
            if ($intent['fulfillment'] === 'compensation_required' && $state === 'delivered') {
                throw new PaymentError('fulfillment_conflict', 409);
            }
            if ($state === 'compensation_requested' && $intent['fulfillment'] !== 'compensation_required') {
                throw new PaymentError('compensation_not_required', 409);
            }
            $version = $intent['version'];
            $intent['version']++;
            $intent['fulfillment'] = $state;
            if ($state === 'compensation_required' && ($intent['compensation'] ?? null) !== 'completed') {
                $intent['compensation'] = $intent['refunded_minor'] === $intent['captured_minor'] ? 'completed' : 'pending';
            }
            if (!$this->saveIntent($intent, $version)) {
                throw new PaymentError('concurrent_fulfillment', 409);
            }
        });
    }

    /** The product has durably acknowledged a business resolution, not a financial reversal. */
    public function completeManualCompensation(string $reference, array $ack): void
    {
        $this->transaction(function () use ($reference, $ack): void {
            $intent = $this->intent($reference, true) ?? throw new PaymentError('not_found', 404);
            if ($intent['fulfillment'] !== 'compensation_requested') {
                throw new PaymentError('compensation_not_requested', 409);
            }
            if (isset($intent['compensation_ack'])) {
                if (CanonicalJson::encode($intent['compensation_ack']) !== CanonicalJson::encode($ack)) {
                    throw new PaymentError('compensation_acknowledgement_conflict', 409);
                }
                return;
            }
            if (($ack['reference'] ?? null) !== $reference || ($ack['kind'] ?? null) !== 'manual_business_resolution' || ($ack['version'] ?? null) !== 1) {
                throw new PaymentError('invalid_compensation_acknowledgement', 422);
            }
            $version = $intent['version'];
            $intent['version']++;
            $intent['compensation'] = 'completed';
            $intent['compensation_ack'] = $ack;
            $intent['compensation_acknowledged_at'] = gmdate('c');
            if (!$this->saveIntent($intent, $version)) {
                throw new PaymentError('concurrent_compensation', 409);
            }
        });
    }

    /** Reserve before I/O. UNKNOWN/PENDING/CONFIRMED never release this reservation. */
    public function reserveRefund(string $intentReference, string $key, int $minor): array
    {
        if ($minor <= 0 || !preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $key)) {
            throw new PaymentError('invalid_refund', 422);
        }
        return $this->transaction(function () use ($intentReference, $key, $minor): array {
            $intent = $this->intent($intentReference, true) ?? throw new PaymentError('not_found', 404);
            $existing = $this->query("SELECT * FROM {$this->tables['refunds']} WHERE intent_reference=? AND request_key=?", [$intentReference, $key])->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                if ((int) $existing['minor'] !== $minor) {
                    throw new PaymentError('idempotency_conflict', 409);
                }
                return $existing;
            }
            if (($intent['disputed_minor'] ?? '0') !== '0' || ($intent['charged_back_minor'] ?? '0') !== '0') {
                throw new PaymentError('dispute_requires_review', 409);
            }
            $reserved = $this->query("SELECT COALESCE(SUM(minor),0) FROM {$this->tables['refunds']} WHERE intent_reference=? AND state IN ('PREPARED','SENDING','UNKNOWN','PENDING','CONFIRMED')", [$intentReference])->fetchColumn();
            $returned = $this->query("SELECT COALESCE(SUM(minor),0) FROM {$this->tables['transactions']} WHERE intent_reference=? AND kind='refund'", [$intentReference])->fetchColumn();
            // Subtract before comparing; never sum potentially overflowing signed integers in PHP.
            $available = (int) $intent['captured_minor'] - (int) $returned;
            if ($available < 0 || (int) $reserved > $available || $minor > $available - (int) $reserved) {
                throw new PaymentError('insufficient_refundable_balance', 409);
            }
            $reference = CanonicalJson::uuid();
            $this->query("INSERT INTO {$this->tables['refunds']} (reference,intent_reference,request_key,remote_key,minor,currency,state) VALUES (?,?,?,?,?,?,'PREPARED')", [$reference, $intentReference, $key, CanonicalJson::uuid(), $minor, $intent['currency']]);
            $this->rearmReconciliation($intentReference);
            return $this->query("SELECT * FROM {$this->tables['refunds']} WHERE reference=?", [$reference])->fetch(PDO::FETCH_ASSOC);
        });
    }

    public function markRefundUncertain(string $reference): void
    {
        $this->query("UPDATE {$this->tables['refunds']} SET state='UNKNOWN' WHERE reference=? AND state IN ('PREPARED','SENDING','PENDING')", [$reference]);
    }

    public function refundOperation(string $reference): ?array
    {
        $row = $this->query("SELECT * FROM {$this->tables['refunds']} WHERE reference=?", [$reference])->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Reviewed individual correlation only: keep the reservation and let read-only recovery finish. */
    public function bindReviewedRefund(string $reference, string $providerId, array $audit): void
    {
        $this->transaction(function () use ($reference, $providerId, $audit): void {
            $observed = $this->refundOperation($reference) ?? throw new PaymentError('not_found', 404);
            $intent = $this->intent($observed['intent_reference'], true) ?? throw new PaymentError('not_found', 404);
            $refund = $this->refundOperation($reference);
            $existing = $intent['refund_resolution_audits'][$reference] ?? null;
            if ($existing !== null) {
                if (CanonicalJson::encode($existing['decision']) !== CanonicalJson::encode($audit)) {
                    throw new PaymentError('refund_resolution_conflict', 409);
                }
                return;
            }
            if (!in_array($refund['state'], ['SENDING', 'UNKNOWN', 'PENDING'], true)
                || ($refund['provider_id'] !== null && $refund['provider_id'] !== $providerId)) {
                throw new PaymentError('refund_resolution_conflict', 409);
            }
            $version = $intent['version'];
            $intent['version']++;
            $intent['refund_resolution_audits'][$reference] = ['decision' => $audit, 'recordedAt' => gmdate('c')];
            if (!$this->saveIntent($intent, $version)) {
                throw new PaymentError('concurrent_refund_resolution', 409);
            }
            $this->query("UPDATE {$this->tables['refunds']} SET state='PENDING',provider_id=? WHERE reference=?", [$providerId, $reference]);
            $this->rearmReconciliation($refund['intent_reference']);
        });
    }

    /** Only sent operations: PREPARED requires a separately authorized command, never worker POST. */
    public function pendingRefundReferences(string $intentReference): array
    {
        return $this->query("SELECT reference FROM {$this->tables['refunds']} WHERE intent_reference=? AND state IN ('SENDING','UNKNOWN','PENDING') ORDER BY reference", [$intentReference])->fetchAll(PDO::FETCH_COLUMN);
    }

    public function refundByKey(string $intentReference, string $key): ?array
    {
        $row = $this->query("SELECT * FROM {$this->tables['refunds']} WHERE intent_reference=? AND request_key=?", [$intentReference,$key])->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function claimRefund(string $reference): bool
    {
        return $this->transaction(function () use ($reference): bool {
            $refund = $this->refundOperation($reference) ?? throw new PaymentError('not_found', 404);
            $intent = $this->intent($refund['intent_reference'], true);
            if ($refund['state'] === 'PREPARED' && (($intent['disputed_minor'] ?? '0') !== '0' || ($intent['charged_back_minor'] ?? '0') !== '0')) {
                throw new PaymentError('dispute_requires_review', 409);
            }
            return $this->query("UPDATE {$this->tables['refunds']} SET state='SENDING' WHERE reference=? AND state='PREPARED'", [$reference])->rowCount() === 1;
        });
    }

    public function observePendingRefund(string $reference, string $providerId): void
    {
        $this->transaction(function () use ($reference, $providerId): void {
            $refund = $this->refundOperation($reference) ?? throw new PaymentError('not_found', 404);
            $this->intent($refund['intent_reference'], true);
            $refund = $this->refundOperation($reference);
            if ($refund['provider_id'] !== null && $refund['provider_id'] !== $providerId) {
                throw new PaymentError('refund_evidence_invalid', 409);
            }
            // A delayed pending response cannot downgrade a completed refund.
            if (in_array($refund['state'], ['SENDING','UNKNOWN','PENDING'], true)) {
                $this->query("UPDATE {$this->tables['refunds']} SET state='PENDING',provider_id=? WHERE reference=?", [$providerId,$reference]);
                if ($refund['provider_id'] === null) {
                    // New correlation evidence may unblock an exhausted queue. Re-observing the
                    // same pending ID must not reset the bounded retry budget on every poll.
                    $this->rearmReconciliation($refund['intent_reference']);
                }
            }
        });
    }

    /** Trusted individual provider evidence, never an inference from aggregate refunded totals. */
    public function confirmRefund(string $reference, string $providerRefundId, \Ronu\PaymentGateways\Money $amount): void
    {
        if ($providerRefundId === '' || strlen($providerRefundId) > 128) {
            throw new PaymentError('refund_evidence_invalid', 422);
        }
        $this->transaction(function () use ($reference, $providerRefundId, $amount): void {
            $initial = $this->query("SELECT * FROM {$this->tables['refunds']} WHERE reference=?", [$reference])->fetch(PDO::FETCH_ASSOC);
            if (!$initial) {
                throw new PaymentError('not_found', 404);
            }
            $intent = $this->intent($initial['intent_reference'], true);
            $refund = $this->query("SELECT * FROM {$this->tables['refunds']} WHERE reference=? FOR UPDATE", [$reference])->fetch(PDO::FETCH_ASSOC);
            if ((int) $refund['minor'] !== $amount->minor || $refund['currency'] !== $amount->currency || ($refund['provider_id'] !== null && $refund['provider_id'] !== $providerRefundId)) {
                throw new PaymentError('refund_evidence_invalid', 409);
            }
            $this->query("INSERT INTO {$this->tables['transactions']} (intent_reference,account_identity,remote_id,kind,minor,currency) VALUES (?,?,?,'refund',?,?) ON CONFLICT(account_identity,remote_id,kind) DO NOTHING", [$intent['reference'], $intent['account_identity'], $providerRefundId, $amount->minor, $amount->currency]);
            $ledger = $this->query("SELECT intent_reference,minor,currency FROM {$this->tables['transactions']} WHERE account_identity=? AND remote_id=? AND kind='refund'", [$intent['account_identity'], $providerRefundId])->fetch(PDO::FETCH_ASSOC);
            if ($ledger['intent_reference'] !== $intent['reference'] || (int) $ledger['minor'] !== $amount->minor || $ledger['currency'] !== $amount->currency) {
                throw new PaymentError('refund_evidence_invalid', 409);
            }
            // One remote refund cannot settle two reservations, even if amounts happen to match.
            $other = $this->query("SELECT reference FROM {$this->tables['refunds']} WHERE intent_reference=? AND provider_id=? AND reference<>?", [$intent['reference'], $providerRefundId, $reference])->fetchColumn();
            if ($other !== false) {
                throw new PaymentError('refund_identity_already_used', 409);
            }
            $this->query("UPDATE {$this->tables['refunds']} SET state='COMPLETED',provider_id=? WHERE reference=?", [$providerRefundId, $reference]);
            $this->updateRefundBalance($intent);
        });
    }

    /** Individual evidence only. Uncorrelated UNKNOWN commands stay reserved, even for equal amounts. */
    public function recordExternalRefund(array $observed, \Ronu\PaymentGateways\Refund $refund): bool
    {
        if ($refund->id === '' || strlen($refund->id) > 128 || !$refund->succeeded() || $refund->amount->minor < 1 || $refund->paymentId !== $observed['provider_id'] || $refund->amount->currency !== $observed['currency']) {
            throw new PaymentError('refund_evidence_invalid', 503);
        }
        return $this->transaction(function () use ($observed, $refund): bool {
            $intent = $this->intent($observed['reference'], true) ?? throw new PaymentError('not_found', 404);
            if ($intent['version'] !== $observed['version']) {
                return false;
            }
            if ($intent['account_identity'] !== $observed['account_identity'] || $intent['provider_id'] !== $refund->paymentId || $intent['currency'] !== $refund->amount->currency) {
                throw new PaymentError('refund_evidence_invalid', 409);
            }
            if ($intent['captured_minor'] === '0') {
                throw new PaymentError('capture_required', 409);
            }
            $key = 'external-refund:'.hash('sha256', $intent['account_identity'].'/'.$refund->id);
            $journal = $this->query("SELECT data FROM {$this->tables['operations']} WHERE scope=? AND account_alias=? AND kind='observation' AND request_key=?", [$intent['scope'],$intent['account_alias'],$key])->fetchColumn();
            $fingerprint = hash('sha256', CanonicalJson::encode(['intent' => $intent['reference'],'account' => $intent['account_identity'],'payment' => $refund->paymentId,'refund' => $refund->id,'minor' => (string)$refund->amount->minor,'currency' => $refund->amount->currency]));
            if ($journal !== false) {
                $operation = json_decode($journal, true, 32, JSON_THROW_ON_ERROR);
                if ($operation['fingerprint'] !== $fingerprint) {
                    throw new PaymentError('refund_evidence_invalid', 409);
                }
                return true;
            }
            $this->query("INSERT INTO {$this->tables['transactions']} (intent_reference,account_identity,remote_id,kind,minor,currency) VALUES (?,?,?,'refund',?,?) ON CONFLICT(account_identity,remote_id,kind) DO NOTHING", [$intent['reference'],$intent['account_identity'],$refund->id,$refund->amount->minor,$refund->amount->currency]);
            $movement = $this->query("SELECT intent_reference,minor,currency FROM {$this->tables['transactions']} WHERE account_identity=? AND remote_id=? AND kind='refund'", [$intent['account_identity'],$refund->id])->fetch(PDO::FETCH_ASSOC);
            if ($movement['intent_reference'] !== $intent['reference'] || (string)$movement['minor'] !== (string)$refund->amount->minor || $movement['currency'] !== $refund->amount->currency) {
                throw new PaymentError('refund_evidence_invalid', 409);
            }
            // Link only an already known provider ID. Never correlate by amount or by refunded totals.
            $local = $this->query("SELECT * FROM {$this->tables['refunds']} WHERE intent_reference=? AND provider_id=?", [$intent['reference'],$refund->id])->fetch(PDO::FETCH_ASSOC);
            if ($local) {
                if ((string)$local['minor'] !== (string)$refund->amount->minor || $local['currency'] !== $refund->amount->currency) {
                    throw new PaymentError('refund_evidence_invalid', 409);
                }
                $this->query("UPDATE {$this->tables['refunds']} SET state='COMPLETED' WHERE reference=?", [$local['reference']]);
            }
            $operation = ['reference' => CanonicalJson::uuid(),'intent_reference' => $intent['reference'],'scope' => $intent['scope'],'account_alias' => $intent['account_alias'],'kind' => 'observation','key' => $key,'fingerprint' => $fingerprint,'fingerprint_version' => 1,'state' => 'CONFIRMED'];
            $this->insertOperation($operation);
            $this->updateRefundBalance($intent);
            $this->recordEvent(CanonicalJson::uuid(), 'payment.refunded', ['version' => 1,'observationVersion' => $intent['version'] + 1,'reference' => $intent['reference'],'subject' => $intent['subject'],'operationReference' => $operation['reference'],'minor' => (string)$refund->amount->minor,'currency' => $refund->amount->currency]);
            return true;
        });
    }

    private function updateRefundBalance(array $intent): void
    {
        // SUM(bigint) is numeric in PostgreSQL. Compare before converting: PHP casts would saturate overflow.
        $row = $this->query("SELECT SUM(minor) AS total,SUM(minor)>?::bigint AS exceeds FROM {$this->tables['transactions']} WHERE intent_reference=? AND kind='refund'", [$intent['captured_minor'],$intent['reference']])->fetch(PDO::FETCH_ASSOC);
        if ($row['exceeds']) {
            throw new PaymentError('refund_balance_mismatch', 409);
        }
        $intent['refunded_minor'] = (string)$row['total'];
        $intent['state'] = $intent['refunded_minor'] === $intent['captured_minor'] ? 'refunded' : 'partially_refunded';
        if ($intent['state'] === 'refunded' && in_array($intent['fulfillment'], ['compensation_required','compensation_requested'], true)) {
            $intent['compensation'] = 'completed';
        }
        if (($intent['disputed_minor'] ?? '0') !== '0' || ($intent['charged_back_minor'] ?? '0') !== '0') {
            $intent['state'] = 'disputed';
        }
        $version = $intent['version'];
        $intent['version']++;
        if (!$this->saveIntent($intent, $version)) {
            throw new PaymentError('concurrent_observation', 409);
        }
    }

    /** Journal, lifecycle ledger and notification commit together. Terminal outcomes cannot regress. */
    public function recordDispute(array $observed, DisputeEvidence $evidence): bool
    {
        return $this->transaction(function () use ($observed, $evidence): bool {
            $intent = $this->intent($observed['reference'], true) ?? throw new PaymentError('not_found', 404);
            if ($intent['version'] !== $observed['version']) {
                return false;
            }
            if ($intent['account_identity'] !== $observed['account_identity'] || $evidence->paymentId !== $intent['provider_id'] || $evidence->amount->currency !== $intent['currency'] || $intent['captured_minor'] === '0' || $evidence->amount->minor > (int)$intent['captured_minor']) {
                throw new PaymentError('dispute_evidence_invalid', 409);
            }
            $key = 'dispute:'.hash('sha256', $intent['account_identity'].'/'.$evidence->id);
            $fingerprint = hash('sha256', CanonicalJson::encode(['intent' => $intent['reference'],'account' => $intent['account_identity'],'payment' => $evidence->paymentId,'dispute' => $evidence->id,'minor' => (string)$evidence->amount->minor,'currency' => $evidence->amount->currency]));
            $operation = $this->one("SELECT data FROM {$this->tables['operations']} WHERE scope=? AND account_alias=? AND kind='dispute' AND request_key=?", [$intent['scope'],$intent['account_alias'],$key]);
            if ($operation !== null) {
                if ($operation['fingerprint'] !== $fingerprint) {
                    throw new PaymentError('dispute_evidence_invalid', 409);
                }
                if ($operation['dispute_state'] === $evidence->state) {
                    return true;
                }
                if ($operation['dispute_state'] !== 'opened') {
                    throw new PaymentError('stale_dispute_observation', 409);
                }
            }
            $kinds = $evidence->state === 'opened' ? ['dispute_opened'] : ['dispute_opened','dispute_'.$evidence->state];
            foreach ($kinds as $kind) {
                $this->query("INSERT INTO {$this->tables['transactions']} (intent_reference,account_identity,remote_id,kind,minor,currency) VALUES (?,?,?,?,?,?) ON CONFLICT(account_identity,remote_id,kind) DO NOTHING", [$intent['reference'],$intent['account_identity'],$evidence->id,$kind,$evidence->amount->minor,$evidence->amount->currency]);
                $row = $this->query("SELECT intent_reference,minor,currency FROM {$this->tables['transactions']} WHERE account_identity=? AND remote_id=? AND kind=?", [$intent['account_identity'],$evidence->id,$kind])->fetch(PDO::FETCH_ASSOC);
                if ($row['intent_reference'] !== $intent['reference'] || (string)$row['minor'] !== (string)$evidence->amount->minor || $row['currency'] !== $evidence->amount->currency) {
                    throw new PaymentError('dispute_evidence_invalid', 409);
                }
            }
            if ($operation === null) {
                $operation = ['reference' => CanonicalJson::uuid(),'intent_reference' => $intent['reference'],'scope' => $intent['scope'],'account_alias' => $intent['account_alias'],'kind' => 'dispute','key' => $key,'fingerprint' => $fingerprint,'fingerprint_version' => 1,'state' => 'CONFIRMED','dispute_state' => $evidence->state,'provider_id' => $evidence->id];
                $this->insertOperation($operation);
            } else {
                $operation['dispute_state'] = $evidence->state;
                $operation['provider_id'] = $evidence->id;
                $this->saveOperation($operation);
            }
            $totals = $this->query("SELECT pending::text,lost::text,(pending<0 OR pending+lost>?::bigint) AS invalid FROM (
                SELECT COALESCE(SUM(CASE WHEN kind='dispute_opened' THEN minor WHEN kind IN ('dispute_won','dispute_lost') THEN -minor ELSE 0 END),0) AS pending,
                COALESCE(SUM(minor) FILTER(WHERE kind='dispute_lost'),0) AS lost
                FROM {$this->tables['transactions']} WHERE intent_reference=?
            ) balances", [$intent['captured_minor'],$intent['reference']])->fetch(PDO::FETCH_ASSOC);
            if ($totals['invalid']) {
                throw new PaymentError('dispute_balance_mismatch', 409);
            }
            $intent['disputed_minor'] = $totals['pending'];
            $intent['charged_back_minor'] = $totals['lost'];
            $intent['state'] = $totals['pending'] !== '0' || $totals['lost'] !== '0' ? 'disputed' : ($intent['refunded_minor'] === '0' ? 'captured' : ($intent['refunded_minor'] === $intent['captured_minor'] ? 'refunded' : 'partially_refunded'));
            $version = $intent['version'];
            $intent['version']++;
            if (!$this->saveIntent($intent, $version)) {
                throw new PaymentError('concurrent_observation', 409);
            }
            $this->recordEvent(CanonicalJson::uuid(), 'payment.dispute_updated', ['version' => 1,'observationVersion' => $intent['version'],'reference' => $intent['reference'],'subject' => $intent['subject'],'operationReference' => $operation['reference'],'state' => $evidence->state,'minor' => (string)$evidence->amount->minor,'currency' => $evidence->amount->currency]);
            $this->rearmReconciliation($intent['reference']);
            return true;
        });
    }

    public function receiveWebhook(Account $account, array $headers, string $rawBody): string
    {
        if ($this->db->inTransaction()) {
            throw new PaymentError('webhook_io_inside_transaction', 503);
        }
        if (strlen($rawBody) > 262144) {
            throw new PaymentError('webhook_too_large', 413);
        }
        // Verification sees untouched bytes. Persist only the signed identity and resource correlation.
        $event = $this->verifyWebhook($account, $headers, $rawBody);
        if ($event->gateway !== $account->gateway->name()) {
            throw new PaymentError('webhook_account_mismatch', 401);
        }
        $resource = $account->gateway->paymentIdForEvent($event);
        if ($event->id === '' || strlen($event->id) > 256 || ($resource !== null && strlen($resource) > 128)) {
            throw new PaymentError('invalid_webhook', 422);
        }
        $this->query("INSERT INTO {$this->tables['inbox']} (account_identity,account_alias,event_id,resource_id) VALUES (?,?,?,?) ON CONFLICT(account_identity,event_id) DO NOTHING", [$account->identity(), $account->alias, $event->id, $resource]);
        $stored = $this->query("SELECT resource_id FROM {$this->tables['inbox']} WHERE account_identity=? AND event_id=?", [$account->identity(), $event->id])->fetch(PDO::FETCH_ASSOC);
        if ($stored['resource_id'] !== $resource) {
            throw new PaymentError('webhook_identity_conflict', 409);
        }
        return $event->id;
    }

    /**
     * During a secret rotation the previous secret is tried only after a MISMATCH on the current one
     * and only while its window is open; malformed or stale signatures are never retried.
     */
    private function verifyWebhook(Account $account, array $headers, string $rawBody): WebhookEvent
    {
        $verifiers = $account->webhookVerifiers(time());
        $last = array_key_last($verifiers);
        foreach ($verifiers as $position => $verifier) {
            try {
                return $verifier->parseWebhook($headers, $rawBody);
            } catch (InvalidWebhookSignature $error) {
                if ($error->reason !== InvalidWebhookSignature::MISMATCH || $position === $last) {
                    throw $error;
                }
            }
        }
        throw new InvalidWebhookSignature(InvalidWebhookSignature::NOT_CONFIGURED);
    }

    public function claimInbox(int $leaseSeconds = 60): ?array
    {
        if ($leaseSeconds < 1 || $leaseSeconds > 3600) {
            throw new PaymentError('invalid_lease', 422);
        }
        return $this->transaction(function () use ($leaseSeconds): ?array {
            $row = $this->query("SELECT * FROM {$this->tables['inbox']} WHERE state<>'PROCESSED' AND attempts<8 AND next_due<=clock_timestamp() AND (leased_until IS NULL OR leased_until<=clock_timestamp()) ORDER BY next_due,id FOR UPDATE SKIP LOCKED LIMIT 1", [])->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            $row['lease_token'] = CanonicalJson::uuid();
            $this->query("UPDATE {$this->tables['inbox']} SET lease_token=?,leased_until=clock_timestamp()+(? * interval '1 second'),attempts=attempts+1 WHERE id=?", [$row['lease_token'], $leaseSeconds, $row['id']]);
            return $row;
        });
    }

    public function finishInbox(int|string $id, string $token, bool $success): bool
    {
        return $this->query("UPDATE {$this->tables['inbox']} SET state=?,lease_token=NULL,leased_until=NULL,next_due=clock_timestamp()+(LEAST(300,5*power(2,attempts-1))*interval '1 second') WHERE id=? AND lease_token=? AND leased_until>clock_timestamp() AND state<>'PROCESSED'", [$success ? 'PROCESSED' : 'FAILED', $id, $token])->rowCount() === 1;
    }

    private function one(string $sql, array $args): ?array
    {
        $value = $this->query($sql, $args)->fetchColumn();
        return $value === false ? null : json_decode($value, true, 32, JSON_THROW_ON_ERROR);
    }

    /** Bounded durable queue; failed resources leave the head of the queue immediately. */
    public function claimReconciliation(int $leaseSeconds = 60): ?array
    {
        if ($leaseSeconds < 1 || $leaseSeconds > 3600) {
            throw new PaymentError('invalid_lease', 422);
        }
        return $this->transaction(function () use ($leaseSeconds): ?array {
            $row = $this->query("SELECT r.reference,i.data FROM {$this->tables['reconciliation']} r JOIN {$this->tables['intents']} i ON i.reference=r.reference
                WHERE reconcile_attempts<8 AND reconcile_due<=clock_timestamp()
                AND (reconcile_until IS NULL OR reconcile_until<=clock_timestamp())
                AND {$this->reconciliationPendingPredicate()}
                ORDER BY reconcile_due,r.reference FOR UPDATE OF r SKIP LOCKED LIMIT 1", [])->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            $token = CanonicalJson::uuid();
            $this->query("UPDATE {$this->tables['reconciliation']} SET reconcile_token=?,reconcile_until=clock_timestamp()+(? * interval '1 second'),reconcile_attempts=reconcile_attempts+1 WHERE reference=?", [$token, $leaseSeconds, $row['reference']]);
            return ['reference' => $row['reference'], 'token' => $token, 'intent' => json_decode($row['data'], true, 32, JSON_THROW_ON_ERROR)];
        });
    }

    public function finishReconciliation(string $reference, string $token, bool $success): bool
    {
        return $this->query("UPDATE {$this->tables['reconciliation']}
            SET reconcile_token=NULL,reconcile_until=NULL,
                reconcile_due=clock_timestamp()+(CASE WHEN ?::boolean THEN 30 ELSE LEAST(300,5*power(2,reconcile_attempts-1)) END * interval '1 second'),
                reconcile_attempts=CASE WHEN ?::boolean THEN 0 ELSE reconcile_attempts END
            WHERE reference=? AND reconcile_token=? AND reconcile_until>clock_timestamp()", [$success ? 'true' : 'false', $success ? 'true' : 'false', $reference, $token])->rowCount() === 1;
    }

    /** A terminal legacy intent may never have owned a queue row. Preserve an active lease. */
    private function rearmReconciliation(string $reference): void
    {
        $this->query("INSERT INTO {$this->tables['reconciliation']} (reference) VALUES (?) ON CONFLICT(reference) DO UPDATE SET reconcile_due=clock_timestamp(),reconcile_attempts=0", [$reference]);
    }

    private function reconciliationPendingPredicate(): string
    {
        return "(i.data->>'state' NOT IN ('captured','refunded','partially_refunded','canceled','failed') OR EXISTS (SELECT 1 FROM {$this->tables['refunds']} f WHERE f.intent_reference=r.reference AND f.state IN ('SENDING','UNKNOWN','PENDING')))";
    }

    /** Queue anatomy for operator surfaces; identifiers only, never payloads or provider bodies. */
    private function queueShape(string $queue): array
    {
        return match ($queue) {
            'inbox' => ['source' => $this->tables['inbox'], 'table' => $this->tables['inbox'], 'id' => 'id', 'attempts' => 'attempts', 'lease' => 'leased_until', 'due' => 'next_due', 'pending' => "state<>'PROCESSED'", 'detail' => 'account_alias', 'lock' => 'FOR UPDATE'],
            'outbox' => ['source' => $this->tables['outbox'], 'table' => $this->tables['outbox'], 'id' => 'event_id', 'attempts' => 'attempts', 'lease' => 'leased_until', 'due' => 'next_due', 'pending' => 'delivered_at IS NULL', 'detail' => 'type', 'lock' => 'FOR UPDATE'],
            'intents' => ['source' => "{$this->tables['reconciliation']} r JOIN {$this->tables['intents']} i USING(reference)", 'table' => $this->tables['reconciliation'], 'id' => 'reference', 'attempts' => 'reconcile_attempts', 'lease' => 'reconcile_until', 'due' => 'reconcile_due', 'pending' => $this->reconciliationPendingPredicate(), 'detail' => "i.data->>'state'", 'lock' => 'FOR UPDATE OF r'],
            default => throw new PaymentError('invalid_queue', 422),
        };
    }

    /**
     * Dead-letter view: exhausted items stay in place; nothing is moved or deleted. A backlog can
     * exceed one page, so an operator can also look up the single item a ticket names.
     */
    public function exhaustedItems(string $queue, int $limit = 200, ?string $item = null): array
    {
        if ($limit < 1 || $limit > 200) {
            throw new PaymentError('invalid_batch_limit', 422);
        }
        $q = $this->queueShape($queue);
        if ($item !== null && !$this->validItem($queue, $item)) {
            throw new PaymentError('invalid_replay_request', 422);
        }
        $only = $item === null ? '' : "AND {$q['id']}::text=?";
        $rows = $this->query("SELECT {$q['id']}::text AS item,{$q['attempts']} AS attempts,{$q['detail']} AS detail,floor(extract(epoch FROM clock_timestamp()-{$q['due']}))::bigint AS overdue_seconds FROM {$q['source']} WHERE {$q['pending']} AND {$q['attempts']}>=8 {$only} ORDER BY {$q['due']},{$q['id']} LIMIT ?", $item === null ? [$limit] : [$item, $limit])->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn (array $row): array => ['queue' => $queue, 'item' => $row['item'], 'attempts' => (int) $row['attempts'], 'detail' => $row['detail'], 'overdueSeconds' => (int) $row['overdue_seconds']], $rows);
    }

    /**
     * Audited manual replay of one exhausted item. It only returns the item to its own retry
     * budget: every queue is read-only towards the provider and product delivery deduplicates by
     * event ID, so a replay can never send money. The audit row and the reset commit together.
     */
    public function replayExhausted(string $queue, string $item, string $actor, string $evidence, string $key): array
    {
        if (!isset($this->tables['replays'])) {
            throw new PaymentError('replay_audit_unavailable', 503);
        }
        foreach ([$actor, $evidence, $key] as $value) {
            if (!preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $value)) {
                throw new PaymentError('invalid_replay_request', 422);
            }
        }
        $q = $this->queueShape($queue);
        if (!$this->validItem($queue, $item)) {
            throw new PaymentError('invalid_replay_request', 422);
        }
        $decision = ['queue' => $queue, 'item' => $item, 'actor' => $actor, 'evidence' => $evidence];
        return $this->transaction(function () use ($q, $decision, $key): array {
            $existing = $this->replayByKey($key);
            if ($existing !== null) {
                return $this->sameReplay($existing, $decision);
            }
            $row = $this->query("SELECT {$q['attempts']} AS attempts,({$q['lease']} IS NOT NULL AND {$q['lease']}>clock_timestamp()) AS leased,({$q['pending']}) AS pending FROM {$q['source']} WHERE {$q['id']}=? {$q['lock']}", [$decision['item']])->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new PaymentError('not_found', 404);
            }
            if (!$row['pending']) {
                throw new PaymentError('replay_not_pending', 409);
            }
            if ((int) $row['attempts'] < 8) {
                throw new PaymentError('replay_not_exhausted', 409);
            }
            if ($row['leased']) {
                throw new PaymentError('replay_lease_active', 409);
            }
            $inserted = $this->query("INSERT INTO {$this->tables['replays']} (request_key,queue,item,actor,evidence,attempts_before) VALUES (?,?,?,?,?,?) ON CONFLICT (request_key) DO NOTHING RETURNING replayed_at", [$key, $decision['queue'], $decision['item'], $decision['actor'], $decision['evidence'], (int) $row['attempts']])->fetchColumn();
            if ($inserted === false) {
                // A concurrent request with the same key committed first.
                return $this->sameReplay($this->replayByKey($key) ?? throw new PaymentError('replay_conflict', 409), $decision);
            }
            $this->query("UPDATE {$q['table']} SET {$q['attempts']}=0,{$q['due']}=clock_timestamp() WHERE {$q['id']}=?", [$decision['item']]);
            return ['replayed' => true] + $decision + ['attemptsBefore' => (int) $row['attempts'], 'recordedAt' => $inserted];
        });
    }

    private function validItem(string $queue, string $item): bool
    {
        return $queue === 'inbox'
            ? preg_match('/^[1-9][0-9]{0,17}$/D', $item) === 1
            : preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $item) === 1;
    }

    private function replayByKey(string $key): ?array
    {
        $row = $this->query("SELECT queue,item,actor,evidence,attempts_before,replayed_at FROM {$this->tables['replays']} WHERE request_key=?", [$key])->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function sameReplay(array $existing, array $decision): array
    {
        foreach ($decision as $field => $value) {
            if ($existing[$field] !== $value) {
                throw new PaymentError('replay_key_conflict', 409);
            }
        }
        return ['replayed' => false] + $decision + ['attemptsBefore' => (int) $existing['attempts_before'], 'recordedAt' => $existing['replayed_at']];
    }

    /** Aggregate gauges only. A stalled item is due, retryable and unclaimed past the threshold. */
    public function metrics(int $staleSeconds = 900): array
    {
        if ($staleSeconds < 60 || $staleSeconds > 86400) {
            throw new PaymentError('invalid_stale_threshold', 422);
        }
        $metrics = [];
        foreach (['inbox', 'outbox', 'intents'] as $queue) {
            $q = $this->queueShape($queue);
            $row = $this->query("SELECT count(*) AS pending,count(*) FILTER(WHERE {$q['attempts']}>=8) AS exhausted,
                count(*) FILTER(WHERE {$q['attempts']}<8 AND {$q['due']}<clock_timestamp()-(? * interval '1 second') AND ({$q['lease']} IS NULL OR {$q['lease']}<=clock_timestamp())) AS stalled,
                COALESCE(floor(extract(epoch FROM clock_timestamp()-min({$q['due']}) FILTER(WHERE {$q['attempts']}<8)))::bigint,0) AS oldest_due_seconds
                FROM {$q['source']} WHERE {$q['pending']}", [$staleSeconds])->fetch(PDO::FETCH_ASSOC);
            $metrics[$queue] = ['pending' => (int) $row['pending'], 'exhausted' => (int) $row['exhausted'], 'stalled' => (int) $row['stalled'], 'oldestDueSeconds' => max(0, (int) $row['oldest_due_seconds'])];
        }
        $metrics['replays'] = isset($this->tables['replays']) ? (int) $this->query("SELECT count(*) FROM {$this->tables['replays']}", [])->fetchColumn() : null;
        return $metrics;
    }

    /** Actionable conditions; each names the operator step, never a customer or provider body. */
    public function alerts(int $staleSeconds = 900): array
    {
        $alerts = [];
        foreach ($this->metrics($staleSeconds) as $queue => $gauge) {
            if (!is_array($gauge)) {
                continue;
            }
            if ($gauge['exhausted'] > 0) {
                $alerts[] = ['queue' => $queue, 'condition' => 'exhausted', 'count' => $gauge['exhausted'], 'action' => "Review with payments:run exhausted --queue={$queue}; after fixing the cause, payments:run replay-exhausted --queue={$queue} --reference=<item> --actor= --evidence= --key="];
            }
            if ($gauge['stalled'] > 0) {
                $alerts[] = ['queue' => $queue, 'condition' => 'stalled', 'count' => $gauge['stalled'], 'action' => 'Due items are not being claimed: check that the scheduler runs payments:run work and that recovery is enabled'];
            }
        }
        return $alerts;
    }

    /** Aggregate counters only; no provider bodies, credentials or personal data. */
    public function queueStatus(): array
    {
        $status = [];
        foreach (['outbox','inbox','intents'] as $queue) {
            $pending = match ($queue) {
                'outbox' => 'delivered_at IS NULL', 'inbox' => "state<>'PROCESSED'", default => $this->reconciliationPendingPredicate()
            };
            $attempts = $queue === 'intents' ? 'reconcile_attempts' : 'attempts';
            $source = $queue === 'intents' ? "{$this->tables['reconciliation']} r JOIN {$this->tables['intents']} i USING(reference)" : $this->tables[$queue];
            $row = $this->query("SELECT count(*) AS pending,count(*) FILTER(WHERE {$attempts}>=8) AS exhausted FROM {$source} WHERE {$pending}", [])->fetch(PDO::FETCH_ASSOC);
            $status[$queue] = ['pending' => (int)$row['pending'], 'exhausted' => (int)$row['exhausted']];
        }
        return $status;
    }

    private function query(string $sql, array $args): \PDOStatement
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($args);
        return $statement;
    }
}
