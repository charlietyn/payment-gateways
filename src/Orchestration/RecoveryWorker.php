<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration;

use Ronu\PaymentGateways\Orchestration\Contracts\{AccountResolver, Fulfillment, FinancialEventConsumer};

/** Called by a dedicated worker/scheduler, never as a side effect of an HTTP GET. */
final class RecoveryWorker
{
    public function __construct(private PostgresStore $store, private Coordinator $coordinator, private AccountResolver $accounts, private Fulfillment $product, private ?FinancialEventConsumer $financialEvents = null, private ?RefundCoordinator $refunds = null, private ?DisputeCoordinator $disputes = null)
    {
    }

    /** @return array<string, int> */
    public function inbox(int $limit = 200): array
    {
        if ($limit < 1 || $limit > 200) {
            throw new PaymentError('invalid_batch_limit', 422);
        }
        $result = ['processed' => 0, 'deferred' => 0];
        for ($index = 0; $index < $limit; $index++) {
            $event = $this->store->claimInbox();
            if ($event === null) {
                break;
            }
            try {
                $account = $this->accounts->historical($event['account_alias'], $event['account_identity']);
                if ($account->identity() !== $event['account_identity'] || $event['resource_id'] === null) {
                    throw new PaymentError('unresolved_webhook', 503);
                }
                $intent = $this->store->intentByProvider($account->identity(), $event['resource_id']);
                $this->store->assertProviderIoAllowed();
                if ($intent === null) {
                    // A precocious webhook may be the first evidence of the resource identity.
                    // This first read resolves only the reference, never financial state.
                    $identity = $account->gateway->getPayment($event['resource_id']);
                    if ($identity === null || $identity->id !== $event['resource_id'] || $identity->reference === null) {
                        throw new PaymentError('remote_resource_missing', 503);
                    }
                    $intent = $this->store->intent($identity->reference);
                }
                if ($intent === null || $intent['account_identity'] !== $account->identity()) {
                    throw new PaymentError('unresolved_webhook', 503);
                }
                $payment = $account->gateway->getPayment($event['resource_id']);
                if ($payment === null || $payment->id !== $event['resource_id']) {
                    throw new PaymentError('remote_resource_missing', 503);
                }
                $operation = $this->store->createOperationFor($intent['reference']);
                if ($operation === null || !$this->coordinator->recoverOperation($operation['reference'], $payment, $intent['version'])) {
                    throw new PaymentError('concurrent_observation', 409);
                }
                if ($this->store->finishInbox($event['id'], $event['lease_token'], true)) {
                    $result['processed']++;
                }
            } catch (\Throwable) {
                $this->store->finishInbox($event['id'], $event['lease_token'], false);
                $result['deferred']++;
            }
        }
        return $result;
    }

    /** @return array<string, int> */
    public function outbox(int $limit = 200): array
    {
        if ($limit < 1 || $limit > 200) {
            throw new PaymentError('invalid_batch_limit', 422);
        }
        $result = ['delivered' => 0, 'failed' => 0];
        for ($index = 0; $index < $limit; $index++) {
            $event = $this->store->claimEvent();
            if ($event === null) {
                break;
            }
            try {
                if (!in_array($event['type'], ['payment.captured','payment.refunded','payment.dispute_updated'], true) || $event['version'] !== 1 || ($event['payload']['version'] ?? null) !== 1) {
                    throw new PaymentError('unsupported_event', 503);
                }
                $intent = $this->store->intent($event['payload']['reference']);
                if ($intent === null || $intent['subject'] !== $event['payload']['subject']) {
                    throw new PaymentError('invalid_event', 503);
                }
                if ($event['type'] !== 'payment.captured') {
                    if ($this->financialEvents === null) {
                        throw new PaymentError('financial_consumer_missing', 503);
                    }
                    $this->financialEvents->consume($event['eventId'], $event['type'], $event['payload']);
                } elseif (!in_array($intent['fulfillment'], ['delivered', 'compensation_requested'], true)) {
                    if ($intent['state'] === 'disputed') {
                        throw new PaymentError('dispute_requires_review', 409);
                    }
                    $eligible = $intent['state'] === 'captured' && $intent['refunded_minor'] === '0';
                    $fulfilled = $eligible && $intent['fulfillment'] !== 'compensation_required' && $this->product->deliver($event['eventId'], $intent['subject'], $event['payload']);
                    if (!$fulfilled) {
                        // Commit the irreversible product decision before external compensation I/O.
                        // A failed compensation must never make a retry attempt delivery again.
                        $this->store->finishFulfillment($intent['reference'], 'compensation_required');
                        $this->product->compensate($event['eventId'], $intent['subject'], $event['payload']);
                    }
                    $this->store->finishFulfillment($intent['reference'], $fulfilled ? 'delivered' : 'compensation_requested');
                }
                if ($this->store->acknowledgeEvent($event['eventId'], $event['leaseToken'])) {
                    $result['delivered']++;
                }
            } catch (\Throwable) {
                $this->store->failEvent($event['eventId'], $event['leaseToken']);
                $result['failed']++;
            }
        }
        return $result;
    }

    /** @return array<string, int> */
    public function reconcile(int $limit = 200): array
    {
        if ($limit < 1 || $limit > 200) {
            throw new PaymentError('invalid_batch_limit', 422);
        }
        $result = ['processed' => 0, 'deferred' => 0];
        for ($index = 0; $index < $limit; $index++) {
            $claim = $this->store->claimReconciliation();
            if ($claim === null) {
                break;
            }
            try {
                $intent = $claim['intent'];
                $disputesResolved = true;
                foreach ($this->store->pendingDisputeIds($intent['reference']) as $disputeId) {
                    try {
                        if ($this->disputes === null || !$this->disputes->observe($intent['reference'], $disputeId)) {
                            $disputesResolved = false;
                        }
                    } catch (\Throwable) {
                        $disputesResolved = false;
                    }
                }
                $intent = $this->store->intent($intent['reference']) ?? throw new PaymentError('not_found', 404);
                $refundsResolved = true;
                // A terminal payment may still own an unresolved refund. Each individual operation
                // is recovered read-only; one missing ID cannot prevent its siblings progressing.
                foreach ($this->store->pendingRefundReferences($intent['reference']) as $reference) {
                    try {
                        if ($this->refunds === null || !$this->refunds->recover($reference)) {
                            $refundsResolved = false;
                        }
                    } catch (\Throwable) {
                        $refundsResolved = false;
                    }
                }
                if (!$disputesResolved || $intent['state'] === 'disputed') {
                    throw new PaymentError('dispute_recovery_pending', 503);
                }
                if (in_array($intent['state'], ['captured','refunded','partially_refunded','canceled','failed'], true)) {
                    if (!$refundsResolved) {
                        throw new PaymentError('refund_recovery_pending', 503);
                    }
                    if ($this->store->finishReconciliation($intent['reference'], $claim['token'], true)) {
                        $result['processed']++;
                    }
                    continue;
                }
                // Refund evidence may have changed the local version. Capture the fresh version
                // before a separate payment observation, never after its provider I/O.
                $intent = $this->store->intent($intent['reference']) ?? throw new PaymentError('not_found', 404);
                // An uncertain create with no provider ID needs a verified webhook or manual evidence.
                // Never repeat create in an attempt to discover that ID.
                if ($intent['provider_id'] === null) {
                    throw new PaymentError('resource_identity_pending', 503);
                }
                $account = $this->accounts->historical($intent['account_alias'], $intent['account_identity']);
                if ($account->identity() !== $intent['account_identity']) {
                    throw new PaymentError('account_identity_changed', 503);
                }
                $this->store->assertProviderIoAllowed();
                $payment = $account->gateway->getPayment($intent['provider_id']);
                if ($payment === null) {
                    throw new PaymentError('remote_resource_missing', 503);
                }
                $operation = $this->store->createOperationFor($intent['reference']);
                if ($operation === null || !$this->coordinator->apply($intent, $payment, $account, $operation['reference'])) {
                    throw new PaymentError('concurrent_observation', 409);
                }
                if (!$refundsResolved) {
                    throw new PaymentError('refund_recovery_pending', 503);
                }
                if ($this->store->finishReconciliation($intent['reference'], $claim['token'], true)) {
                    $result['processed']++;
                }
            } catch (\Throwable) {
                $this->store->finishReconciliation($claim['reference'], $claim['token'], false);
                $result['deferred']++;
            }
        }
        return $result;
    }
}
