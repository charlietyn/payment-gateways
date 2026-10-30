<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration;

use Ronu\PaymentGateways\{CreatePayment, Money, Payment, PaymentStatus};
use Ronu\PaymentGateways\Orchestration\Contracts\{AccountResolver, PaymentStore, SubjectResolver};

/** Financial coordination; no product classes, Laravel facades or request globals. */
final class Coordinator
{
    public function __construct(private PaymentStore $store, private SubjectResolver $subjects, private AccountResolver $accounts, private \Closure $clock)
    {
    }

    /** @return array<string, mixed> */
    public function checkout(Actor $actor, string $subject, string $revision, string $key, ?string $alias = null): array
    {
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $key)) {
            throw new PaymentError('invalid_idempotency_key', 422);
        }
        // Replay precedes defaults, current quotes and readiness; none may divert a historical request.
        $existing = $this->store->operationByKey($actor->scope(), $key, $alias);
        if ($existing !== null) {
            $replay = $this->replay($actor, $existing, $subject, $revision);
            if ($existing['state'] !== 'PREPARED') {
                return $replay;
            }
            $historical = $this->store->intent($existing['intent_reference']) ?? throw new PaymentError('not_found', 404);
            $account = $this->accounts->historical($historical['account_alias'], $historical['account_identity']);
            if ($account->identity() !== $historical['account_identity']) {
                throw new PaymentError('account_identity_changed', 503);
            }
        } else {
            $account = $this->accounts->forNew($actor, $alias);
        }
        $actor->require('create', $account->alias);
        if (!$account->acceptNew) {
            throw new PaymentError('not_ready', 503);
        }
        $quote = $this->subjects->resolve($actor, $subject);
        if ($quote->ownerScope !== $actor->scope()) {
            throw new PaymentError('not_found', 404);
        }
        if ($quote->subject !== $subject || $quote->revision !== $revision || $quote->expiresAt <= ($this->clock)() || $quote->amount->currency !== $account->currency) {
            throw new PaymentError('invalid_quote', 422);
        }
        if (isset($historical) && ((string) $quote->amount->minor !== $historical['amount_minor'] || $quote->amount->currency !== $historical['currency'])) {
            throw new PaymentError('idempotency_conflict', 409);
        }
        $operation = $this->store->transaction(function () use ($actor, $quote, $key, $account): array {
            $this->store->lock($actor->scope(), 'key/'.$account->alias.'/'.$key);
            $this->store->lock($actor->scope(), $quote->subject);
            $existing = $this->store->operationByKey($actor->scope(), $key, $account->alias);
            if ($existing !== null) {
                return $existing;
            }
            if ($this->store->intentBySubject($actor->scope(), $quote->subject) !== null) {
                throw new PaymentError('subject_already_reserved', 409);
            }
            $intent = [
                'reference' => CanonicalJson::uuid(), 'scope' => $actor->scope(), 'subject' => $quote->subject, 'quote_revision' => $quote->revision,
                'account_alias' => $account->alias, 'account_identity' => $account->identity(), 'configuration_version' => $account->configurationVersion,
                'currency' => $quote->amount->currency, 'amount_minor' => (string) $quote->amount->minor,
                'state' => 'created', 'fulfillment' => 'pending', 'captured_minor' => '0', 'refunded_minor' => '0',
                'provider_id' => null, 'transaction_id' => null, 'version' => 0, 'next_action' => null,
            ];
            $operation = [
                'reference' => CanonicalJson::uuid(), 'intent_reference' => $intent['reference'], 'scope' => $intent['scope'], 'account_alias' => $account->alias,
                'kind' => 'create', 'key' => $key, 'remote_key' => CanonicalJson::uuid(), 'fingerprint_version' => 1,
                'fingerprint' => hash('sha256', CanonicalJson::encode(['subject' => $quote->subject, 'revision' => $quote->revision, 'minor' => (string) $quote->amount->minor, 'currency' => $quote->amount->currency, 'account' => $account->identity()])),
                'state' => 'PREPARED', 'created_at' => ($this->clock)(),
            ];
            $this->store->insertIntent($intent);
            $this->store->insertOperation($operation);
            return $operation;
        });
        // Recheck payload if another process won the same idempotency key.
        $this->replay($actor, $operation, $subject, $revision);
        $claimed = $this->store->transaction(function () use ($operation): bool {
            $this->store->intent($operation['intent_reference'], true);
            $current = $this->store->operation($operation['reference']) ?? throw new PaymentError('not_found', 404);
            if ($current['state'] !== 'PREPARED') {
                return false;
            }
            $current['state'] = 'SENDING';
            $this->store->saveOperation($current);
            return true;
        });
        if (!$claimed) {
            return $this->operationView($actor, $operation['reference'], 'create');
        }
        $intent = $this->store->intent($operation['intent_reference']) ?? throw new PaymentError('not_found', 404);
        try {
            $payment = $account->gateway->createPayment(new CreatePayment(
                amount: $quote->amount,
                reference: $intent['reference'],
                idempotencyKey: $operation['remote_key'],
            ));
            $this->apply($intent, $payment, $account, $operation['reference']);
        } catch (\Throwable) {
            // Even a malformed 2xx may have charged money. Never turn uncertainty into a retry.
            $this->store->transaction(function () use ($operation): void {
                $this->store->intent($operation['intent_reference'], true);
                $current = $this->store->operation($operation['reference']) ?? throw new PaymentError('not_found', 404);
                if ($current['state'] === 'SENDING') {
                    $current['state'] = 'UNKNOWN';
                    $this->store->saveOperation($current);
                }
            });
        }
        return $this->operationView($actor, $operation['reference'], 'create');
    }

    /**
     * @param array<string, mixed> $operation
     * @return array<string, mixed>
     */
    private function replay(Actor $actor, array $operation, string $subject, string $revision): array
    {
        $actor->require('create', $operation['account_alias']);
        $intent = $this->store->intent($operation['intent_reference']) ?? throw new PaymentError('not_found', 404);
        if ($intent['subject'] !== $subject || $intent['quote_revision'] !== $revision) {
            throw new PaymentError('idempotency_conflict', 409);
        }
        return $this->operationView($actor, $operation['reference'], 'create');
    }

    /** @return array<string, mixed> */
    public function operationView(Actor $actor, string $reference, string $capability = 'read'): array
    {
        $operation = $this->store->operation($reference);
        if ($operation === null || $operation['scope'] !== $actor->scope()) {
            throw new PaymentError('not_found', 404);
        }
        $actor->require($capability, $operation['account_alias']);
        $intent = $this->store->intent($operation['intent_reference']) ?? throw new PaymentError('not_found', 404);
        return [
            'schemaVersion' => 1, 'operationReference' => $reference, 'reference' => $intent['reference'], 'operationState' => $operation['state'],
            'financialState' => $intent['state'], 'fulfillmentState' => $intent['fulfillment'],
            'compensationState' => $intent['compensation'] ?? 'not_requested',
            'amount' => (new Money((int) $intent['amount_minor'], $intent['currency']))->toDecimal(), 'currency' => $intent['currency'],
            'nextAction' => $intent['next_action'], 'httpStatus' => $operation['state'] === 'CONFIRMED' ? 200 : 202,
        ];
    }

    /** Background reconciliation only. HTTP GET never calls this. */
    public function refresh(string $reference): bool
    {
        $intent = $this->store->intent($reference);
        if ($intent === null || $intent['provider_id'] === null) {
            return false;
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
        return $this->apply($intent, $payment, $account);
    }

    /** @param array<string, mixed> $observed */
    public function apply(array $observed, Payment $payment, Account $account, ?string $operationReference = null): bool
    {
        $amount = new Money((int) $observed['amount_minor'], $observed['currency']);
        if ($account->identity() !== $observed['account_identity'] || $payment->gateway !== $account->gateway->name() || !$payment->matches($observed['reference'], $amount) || $payment->id === '' || ($observed['provider_id'] !== null && $payment->id !== $observed['provider_id'])) {
            throw new PaymentError('remote_mismatch', 503);
        }
        if ($payment->status->isPaid() && ($payment->capturedAmount === null || !$payment->capturedAmount->equals($amount) || $payment->transactionId === null || $payment->transactionId === '')) {
            throw new PaymentError('capture_evidence_missing', 503);
        }
        if ($payment->refundedAmount !== null && ($payment->refundedAmount->currency !== $amount->currency || $payment->refundedAmount->minor > $amount->minor)) {
            throw new PaymentError('refund_evidence_invalid', 503);
        }
        // External refunds/disputes need individual movements. Do not infer a ledger from a total.
        if (in_array($payment->status, [PaymentStatus::PartiallyRefunded, PaymentStatus::Refunded, PaymentStatus::Disputed], true) || ($payment->refundedAmount !== null && $payment->refundedAmount->minor > 0)) {
            throw new PaymentError('external_adjustment_requires_evidence', 503);
        }
        $action = null;
        if ($payment->nextAction !== null) {
            $parts = parse_url($payment->nextAction->url);
            $origin = is_array($parts) ? ($parts['scheme'] ?? '').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '') : '';
            if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || !in_array($origin, $account->actionOrigins, true) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
                throw new PaymentError('unsafe_next_action', 503);
            }
            $action = ['type' => $payment->nextAction->type, 'url' => $payment->nextAction->url, 'returnsToMerchant' => $payment->nextAction->returnsToMerchant];
        }
        return $this->store->transaction(function () use ($observed, $payment, $operationReference, $action): bool {
            $current = $this->store->intent($observed['reference'], true) ?? throw new PaymentError('not_found', 404);
            if ($current['version'] !== $observed['version']) {
                return false;
            }
            if (($current['disputed_minor'] ?? '0') !== '0' || ($current['charged_back_minor'] ?? '0') !== '0') {
                throw new PaymentError('dispute_requires_review', 409);
            }
            if ($current['captured_minor'] !== '0' && $payment->status !== PaymentStatus::Captured) {
                throw new PaymentError('stale_financial_observation', 409);
            }
            if ($current['refunded_minor'] !== '0' && $payment->status === PaymentStatus::Captured) {
                throw new PaymentError('stale_refund_observation', 409);
            }
            if ($current['transaction_id'] !== null && $payment->transactionId !== $current['transaction_id']) {
                throw new PaymentError('transaction_identity_changed', 409);
            }
            $current['provider_id'] = $payment->id;
            $current['transaction_id'] = $payment->transactionId;
            $current['state'] = $payment->status->value;
            $current['next_action'] = $action;
            $current['version']++;
            if ($payment->status === PaymentStatus::Captured) {
                if ($payment->capturedAmount === null || $payment->transactionId === null) {
                    throw new PaymentError('capture_evidence_missing', 503);
                }
                $current['captured_minor'] = (string) $payment->capturedAmount->minor;
                $this->store->recordCapture($current, $payment->transactionId);
                if ($observed['captured_minor'] === '0') {
                    $this->store->recordEvent(CanonicalJson::uuid(), 'payment.captured', ['version' => 1, 'reference' => $current['reference'], 'subject' => $current['subject'], 'minor' => $current['amount_minor'], 'currency' => $current['currency']]);
                }
            }
            if (!$this->store->saveIntent($current, $observed['version'])) {
                throw new PaymentError('concurrent_observation', 409);
            }
            if ($operationReference !== null) {
                $operation = $this->store->operation($operationReference);
                if ($operation === null || $operation['intent_reference'] !== $current['reference']) {
                    throw new PaymentError('operation_mismatch', 409);
                }
                $operation['state'] = 'CONFIRMED';
                $this->store->saveOperation($operation);
            }
            return true;
        });
    }

    /** expectedVersion MUST be captured before the reader's provider I/O. This method never POSTs. */
    public function recoverOperation(string $operationReference, Payment $payment, int $expectedVersion): bool
    {
        $operation = $this->store->operation($operationReference) ?? throw new PaymentError('not_found', 404);
        $intent = $this->store->intent($operation['intent_reference']) ?? throw new PaymentError('not_found', 404);
        if ($intent['version'] !== $expectedVersion) {
            return false;
        }
        $account = $this->accounts->historical($intent['account_alias'], $intent['account_identity']);
        if (!in_array($operation['state'], ['SENDING', 'UNKNOWN', 'CONFIRMED'], true)) {
            throw new PaymentError('operation_not_sent', 409);
        }
        return $this->apply($intent, $payment, $account, $operationReference);
    }
}
