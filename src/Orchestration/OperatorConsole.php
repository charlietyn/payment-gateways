<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration;

use Ronu\PaymentGateways\{Capability, Money};
use Ronu\PaymentGateways\Orchestration\Contracts\AccountResolver;

/**
 * Read side of the back office (0.10.0): list, detail, incidents and the actions that are EFFECTIVELY
 * available for one payment. It never calls a provider and never writes; the writes it advertises are
 * RefundCoordinator::requestAsOperator and requestReconciliation.
 *
 * Three states are reported separately because they mean different things to an operator:
 * financial (what the provider says about the money), fulfillment (what the product delivered) and
 * compensation (money returned because the product could not deliver).
 */
final class OperatorConsole
{
    /** PaymentStatus values, as stored on the intent. */
    public const FINANCIAL_STATES = ['created', 'requires_action', 'processing', 'approved', 'authorized', 'captured', 'partially_refunded', 'refunded', 'canceled', 'failed', 'disputed', 'unknown'];
    public const FULFILLMENT_STATES = ['pending', 'delivered', 'rejected', 'compensation_required', 'compensation_requested', 'compensated', 'compensation_failed'];

    public function __construct(private PostgresStore $store, private AccountResolver $accounts, private bool $refundsAccepted, private bool $recoveryEnabled)
    {
    }

    /**
     * @param array{state?:string,fulfillment?:string,account?:string,reference?:string,subject?:string} $filters
     * @return array<string, mixed>
     */
    public function list(Operator $operator, array $filters, int $page, int $perPage): array
    {
        if ($page < 1 || $page > 10000 || $perPage < 1 || $perPage > 100) {
            throw new PaymentError('invalid_request', 422);
        }
        if (isset($filters['account']) && !in_array($filters['account'], $operator->accounts, true)) {
            // Asking for an account you cannot access is not an error that reveals it exists.
            return ['data' => [], 'meta' => ['page' => $page, 'perPage' => $perPage, 'total' => 0]];
        }
        $result = $this->store->operatorIntents($operator->project, $operator->tenant, $operator->legacyHistory, $operator->accounts, $filters, $perPage, ($page - 1) * $perPage);
        return [
            'data' => array_map(fn (array $intent): array => $this->summary($intent), $result['items']),
            'meta' => ['page' => $page, 'perPage' => $perPage, 'total' => $result['total']],
        ];
    }

    /** @return array<string, mixed> */
    public function detail(Operator $operator, string $reference): array
    {
        $intent = $this->visible($operator, $reference);
        $timeline = $this->store->operatorTimeline($intent);
        $created = null;
        foreach ($timeline['operations'] as $operation) {
            if ($operation['kind'] === 'create') {
                $created = $operation['createdAt'];
            }
        }
        return $this->summary($intent + ['created_at' => $created]) + [
            'capturedAmount' => (new Money((int) $intent['captured_minor'], $intent['currency']))->toDecimal(),
            'refundedAmount' => (new Money((int) $intent['refunded_minor'], $intent['currency']))->toDecimal(),
            'operations' => $timeline['operations'],
            'refunds' => array_map(static fn (array $refund): array => [
                'operationReference' => $refund['reference'], 'operationState' => $refund['state'],
                'amount' => (new Money($refund['minor'], $refund['currency']))->toDecimal(), 'currency' => $refund['currency'],
                'origin' => $refund['compensation'] ? 'compensation' : ($refund['byOperator'] ? 'operator' : 'client'),
            ], $timeline['refunds']),
            'notifications' => $timeline['outbox'],
            'webhooks' => $timeline['inbox'],
            'reconciliation' => $timeline['reconciliation'],
            'incidents' => $this->incidents($intent, $timeline),
            'actions' => $this->actions($operator, $intent),
        ];
    }

    /** @return array<string, mixed> */
    public function requestReconciliation(Operator $operator, string $reference): array
    {
        $intent = $this->visible($operator, $reference);
        $operator->require('recover', $intent['account_alias']);
        if (!$this->recoveryEnabled) {
            throw new PaymentError('recovery_disabled', 503);
        }
        $outcome = $this->store->requestReconciliation($reference);
        return match ($outcome) {
            'scheduled', 'leased' => ['reference' => $reference, 'reconciliation' => $outcome, 'httpStatus' => 202],
            'exhausted' => throw new PaymentError('reconciliation_exhausted_requires_review', 409),
            default => throw new PaymentError('reconciliation_not_pending', 409),
        };
    }

    /** @return array<string, mixed> */
    private function visible(Operator $operator, string $reference): array
    {
        $intent = $this->store->intent($reference);
        if ($intent === null || !$operator->sees($intent)) {
            throw new PaymentError('not_found', 404);
        }
        $operator->require('read', $intent['account_alias']);
        return $intent;
    }

    /**
     * @param array<string, mixed> $intent
     * @return array<string, mixed>
     */
    private function summary(array $intent): array
    {
        return [
            'reference' => $intent['reference'],
            'subject' => SubjectAttempt::base($intent['subject']),
            'attempt' => SubjectAttempt::number($intent['subject']),
            'account' => $intent['account_alias'],
            'financialState' => $intent['state'],
            'fulfillmentState' => $intent['fulfillment'],
            'compensationState' => $intent['compensation'] ?? 'not_requested',
            'amount' => (new Money((int) $intent['amount_minor'], $intent['currency']))->toDecimal(),
            'currency' => $intent['currency'],
            'createdAt' => $intent['created_at'] ?? null,
            'legacy' => !array_key_exists('project', $intent),
        ];
    }

    /**
     * What needs a human: each entry names a condition, never a provider body.
     *
     * @param array<string, mixed> $intent
     * @param array<string, mixed> $timeline
     * @return list<array<string, string>>
     */
    private function incidents(array $intent, array $timeline): array
    {
        $incidents = [];
        foreach ($timeline['operations'] as $operation) {
            if (in_array($operation['state'], ['UNKNOWN', 'SENDING'], true)) {
                $incidents[] = ['condition' => 'operation_uncertain', 'subject' => $operation['reference'], 'action' => 'recovery reads the provider; the operation is never resent'];
            }
        }
        foreach ($timeline['refunds'] as $refund) {
            if (in_array($refund['state'], ['SENDING', 'UNKNOWN', 'PENDING'], true)) {
                $incidents[] = ['condition' => 'refund_unresolved', 'subject' => $refund['reference'], 'action' => 'recovery reads the provider; the refund is never resent'];
            } elseif ($refund['state'] === 'FAILED') {
                $incidents[] = ['condition' => 'refund_failed', 'subject' => $refund['reference'], 'action' => 'the reserved balance was released; review before trying again'];
            }
        }
        foreach ($timeline['outbox'] as $event) {
            if (!$event['delivered'] && $event['attempts'] >= 8) {
                $incidents[] = ['condition' => 'product_notification_exhausted', 'subject' => $event['type'], 'action' => 'payments:run exhausted --queue=outbox, then a reviewed replay'];
            } elseif (!$event['delivered'] && $event['attempts'] > 0) {
                $incidents[] = ['condition' => 'product_notification_retrying', 'subject' => $event['type'], 'action' => 'the worker keeps delivering it'];
            }
        }
        if (($timeline['reconciliation']['attempts'] ?? 0) >= 8 && $this->store->reconciliationPending($intent['reference'])) {
            $incidents[] = ['condition' => 'reconciliation_exhausted', 'subject' => $intent['reference'], 'action' => 'payments:run exhausted --queue=intents, then a reviewed replay with evidence'];
        }
        if (in_array($intent['fulfillment'], ['rejected', 'compensation_required', 'compensation_requested', 'compensation_failed'], true)) {
            $incidents[] = ['condition' => 'fulfillment_'.$intent['fulfillment'], 'subject' => $intent['reference'], 'action' => 'the product could not deliver; compensation follows the product rules'];
        }
        if (($intent['disputed_minor'] ?? '0') !== '0' || ($intent['charged_back_minor'] ?? '0') !== '0') {
            $incidents[] = ['condition' => 'dispute_open', 'subject' => $intent['reference'], 'action' => 'answer the dispute with the provider'];
        }
        return $incidents;
    }

    /**
     * The actions THIS operator can take on THIS payment right now, each with the reason it cannot when
     * it cannot. A refund route existing does not mean refunds are enabled.
     *
     * @param array<string, mixed> $intent
     * @return array<string, array<string, mixed>>
     */
    private function actions(Operator $operator, array $intent): array
    {
        $refund = ['available' => false, 'reason' => null, 'maximum' => null, 'partial' => false];
        $refundable = (int) $intent['captured_minor'] - (int) $intent['refunded_minor'] - $this->store->reservedRefundMinor($intent['reference']);
        $refund['reason'] = match (true) {
            !$operator->can('refund', $intent['account_alias']) => 'forbidden',
            !$this->refundsAccepted => 'refunds_disabled',
            // A fully refunded payment was captured: what it lacks is a balance, not a capture.
            !in_array($intent['state'], ['captured', 'partially_refunded', 'refunded'], true) || $intent['provider_id'] === null => 'not_captured',
            $refundable < 1 || $intent['state'] === 'refunded' => 'nothing_refundable',
            default => null,
        };
        if ($refund['reason'] === null) {
            try {
                $account = $this->accounts->historical($intent['account_alias'], $intent['account_identity']);
                $refund = ['available' => true, 'reason' => null,
                    'maximum' => (new Money($refundable, $intent['currency']))->toDecimal(),
                    'partial' => $account->gateway->supports(Capability::PartialRefund)];
            } catch (PaymentError $error) {
                $refund['reason'] = $error->reason;
            }
        }
        $reconcile = ['available' => false, 'reason' => match (true) {
            !$operator->can('recover', $intent['account_alias']) => 'forbidden',
            !$this->recoveryEnabled => 'recovery_disabled',
            !$this->store->reconciliationPending($intent['reference']) => 'not_pending',
            default => null,
        }];
        $reconcile['available'] = $reconcile['reason'] === null;
        return ['refund' => $refund, 'reconcile' => $reconcile];
    }
}
