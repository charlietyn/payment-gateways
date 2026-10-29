<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration;

use Ronu\PaymentGateways\{Capability, Money, Refund};
use Ronu\PaymentGateways\Orchestration\Contracts\{AccountResolver, RefundEvidenceReader};

/** No HTTP surface is registered. New refund writes remain disabled unless the consumer certifies them. */
final class RefundCoordinator
{
    public function __construct(private PostgresStore $store, private AccountResolver $accounts, private RefundEvidenceReader $reader, private bool $acceptNew = false)
    {
    }

    public function request(Actor $actor, string $intentReference, Money $amount, string $key): array
    {
        $intent = $this->ownedIntent($actor, $intentReference, 'refund');
        if (str_starts_with($key, 'compensation:')) {
            throw new PaymentError('reserved_idempotency_key', 422);
        }
        $refund = $this->send($intent, $amount, $key);
        return $this->view($actor, $refund['reference'], 'refund');
    }

    /** Trusted product/worker port, never an HTTP action. The durable rejection authorizes compensation. */
    public function requestCompensation(string $intentReference): array
    {
        $intent = $this->store->intent($intentReference) ?? throw new PaymentError('not_found', 404);
        if (!in_array($intent['fulfillment'], ['compensation_required','compensation_requested'], true)) {
            throw new PaymentError('compensation_not_required', 409);
        }
        if (!in_array($intent['state'], ['captured','partially_refunded','refunded'], true)) {
            throw new PaymentError('compensation_balance_requires_review', 409);
        }
        $key = 'compensation:v1:'.$intentReference;
        $existing = $this->store->refundByKey($intentReference, $key);
        $minor = $existing === null ? (int)$intent['captured_minor'] - (int)$intent['refunded_minor'] : (int)$existing['minor'];
        if ($existing === null && $minor === 0 && ($intent['compensation'] ?? null) === 'completed') {
            return ['operationReference' => null,'operationState' => 'COMPLETED'];
        }
        if ($minor < 1) {
            throw new PaymentError('compensation_balance_requires_review', 409);
        }
        $refund = $this->send($intent, new Money($minor, $intent['currency']), $key);
        return ['operationReference' => $refund['reference'],'operationState' => $refund['state']];
    }

    private function send(array $intent, Money $amount, string $key): array
    {
        if ($amount->currency !== $intent['currency'] || $amount->minor < 1) {
            throw new PaymentError('invalid_refund', 422);
        }
        // Retirement blocks new writes, not observation/acknowledgment of a historical command.
        // In particular a product callback may still need to acknowledge a completed compensation.
        $existing = $this->store->refundByKey($intent['reference'], $key);
        if ($existing !== null) {
            if ((int)$existing['minor'] !== $amount->minor || $existing['currency'] !== $amount->currency) {
                throw new PaymentError('idempotency_conflict', 409);
            }
            if ($existing['state'] !== 'PREPARED') {
                return $existing;
            }
        }
        if (!$this->acceptNew) {
            throw new PaymentError('refunds_disabled', 503);
        }
        $account = $this->historical($intent);
        if ($amount->minor < (int)$intent['captured_minor'] && !$account->gateway->supports(Capability::PartialRefund)) {
            throw new PaymentError('partial_refund_unsupported', 422);
        }
        $refund = $this->store->reserveRefund($intent['reference'], $key, $amount->minor);
        if ($this->store->claimRefund($refund['reference'])) {
            // SENDING has committed before I/O. Crash or any unclassified response retains the reservation.
            try {
                $evidence = $account->gateway->refund($intent['provider_id'], $amount, $refund['remote_key']);
                $this->apply($refund, $intent, $evidence);
            } catch (\Throwable) {
                $this->store->markRefundUncertain($refund['reference']);
            }
        }
        return $this->store->refundOperation($refund['reference']);
    }

    /** Explicit worker recovery, independent of accept-new. Never retries a write. */
    public function recover(string $reference): bool
    {
        $refund = $this->store->refundOperation($reference) ?? throw new PaymentError('not_found', 404);
        if ($refund['state'] === 'COMPLETED') {
            return true;
        }
        if (!in_array($refund['state'], ['SENDING','UNKNOWN','PENDING'], true)) {
            throw new PaymentError('refund_not_sent', 409);
        }
        $intent = $this->store->intent($refund['intent_reference']);
        $account = $this->historical($intent);
        $this->store->assertProviderIoAllowed();
        $evidence = $this->reader->read($account, $intent['provider_id'], $refund['provider_id'], $refund['remote_key']);
        if ($evidence === null) {
            return false;
        }
        $this->apply($refund, $intent, $evidence);
        return $this->store->refundOperation($reference)['state'] === 'COMPLETED';
    }

    /** Console-only reviewed correlation; the provider must independently prove ID, payment and amount. */
    public function recoverReviewed(string $reference, string $providerId, string $actor, string $review): bool
    {
        foreach ([$providerId, $actor, $review] as $value) {
            if (!preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $value)) {
                throw new PaymentError('invalid_refund_resolution', 422);
            }
        }
        $refund = $this->store->refundOperation($reference) ?? throw new PaymentError('not_found', 404);
        $intent = $this->store->intent($refund['intent_reference']);
        $account = $this->historical($intent);
        $this->store->assertProviderIoAllowed();
        $evidence = $this->reader->read($account, $intent['provider_id'], $providerId, '');
        if ($evidence === null) {
            return false;
        }
        if ($evidence->id !== $providerId || $evidence->paymentId !== $intent['provider_id']
            || $evidence->amount->minor !== (int) $refund['minor'] || $evidence->amount->currency !== $refund['currency']) {
            throw new PaymentError('refund_evidence_invalid', 503);
        }
        $this->store->bindReviewedRefund($reference, $providerId, ['version' => 1, 'providerId' => $providerId, 'actor' => $actor, 'review' => $review]);
        $this->apply($this->store->refundOperation($reference), $intent, $evidence);
        return $this->store->refundOperation($reference)['state'] === 'COMPLETED';
    }

    public function view(Actor $actor, string $reference, string $capability = 'read'): array
    {
        if (!in_array($capability, ['read','refund'], true)) {
            throw new PaymentError('forbidden', 403);
        }
        $refund = $this->store->refundOperation($reference) ?? throw new PaymentError('not_found', 404);
        $this->ownedIntent($actor, $refund['intent_reference'], $capability);
        return ['operationReference' => $refund['reference'],'reference' => $refund['intent_reference'],
            'operationState' => $refund['state'],'amount' => (new Money((int)$refund['minor'], $refund['currency']))->toDecimal(),
            'currency' => $refund['currency'],'httpStatus' => $refund['state'] === 'COMPLETED' ? 200 : 202];
    }

    private function ownedIntent(Actor $actor, string $reference, string $capability): array
    {
        $intent = $this->store->intent($reference);
        if ($intent === null || $intent['scope'] !== $actor->scope()) {
            throw new PaymentError('not_found', 404);
        }
        $actor->require($capability, $intent['account_alias']);
        return $intent;
    }

    private function historical(array $intent): Account
    {
        if ($intent['provider_id'] === null || $intent['captured_minor'] === '0') {
            throw new PaymentError('capture_required', 409);
        }
        $account = $this->accounts->historical($intent['account_alias'], $intent['account_identity']);
        if ($account->identity() !== $intent['account_identity']) {
            throw new PaymentError('account_identity_changed', 503);
        }
        return $account;
    }

    private function apply(array $refund, array $intent, Refund $evidence): void
    {
        if ($evidence->id === '' || strlen($evidence->id) > 128 || $evidence->paymentId !== $intent['provider_id'] ||
            $evidence->amount->minor !== (int)$refund['minor'] || $evidence->amount->currency !== $refund['currency'] ||
            ($refund['provider_id'] !== null && $refund['provider_id'] !== $evidence->id)) {
            throw new PaymentError('refund_evidence_invalid', 503);
        }
        if ($evidence->succeeded()) {
            $this->store->confirmRefund($refund['reference'], $evidence->id, $evidence->amount);
        } elseif (in_array(strtolower($evidence->status), ['pending','processing','in_process'], true)) {
            $this->store->observePendingRefund($refund['reference'], $evidence->id);
        } else {
            throw new PaymentError('refund_outcome_unresolved', 503);
        }
    }
}
