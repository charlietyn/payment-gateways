<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration\Laravel;

use Illuminate\Console\Command;
use Ronu\PaymentGateways\Orchestration\PaymentError;

final class PaymentCommand extends Command
{
    protected $signature = 'payments:run {action=status : status|work|observe-dispute|observe-refund|recover-refund|compensation-status|acknowledge-compensation|exhausted|replay-exhausted|metrics|alerts} {--reference=} {--case=} {--actor=} {--evidence=} {--key=} {--queue=} {--stale=900} {--limit=200}';
    protected $description = 'Inspect recovery gauges and alerts, perform bounded read-only reconciliation or an audited replay of an exhausted item';

    public function handle(PaymentRuntime $runtime): int
    {
        try {
            $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
            if ($limit === false || $limit < 1 || $limit > 200) {
                throw new PaymentError('invalid_batch_limit', 422);
            }
            $stale = filter_var($this->option('stale'), FILTER_VALIDATE_INT);
            if ($stale === false) {
                throw new PaymentError('invalid_stale_threshold', 422);
            }
            if ($this->argument('action') === 'alerts') {
                // Exit 2 lets a scheduler or monitor page on actionable conditions.
                $alerts = $runtime->store()->alerts($stale);
                $this->line(json_encode(['alerts' => $alerts], JSON_THROW_ON_ERROR));
                return $alerts === [] ? self::SUCCESS : 2;
            }
            $result = match ($this->argument('action')) {
                'status' => $runtime->store()->queueStatus(),
                'work' => $this->work($runtime, $limit),
                'observe-dispute' => ['observed' => $runtime->disputes()->observe((string) $this->option('reference'), (string) $this->option('case'))],
                'observe-refund' => ['observed' => $runtime->externalRefunds()->observe((string) $this->option('reference'), (string) $this->option('case'))],
                'recover-refund' => ['recovered' => $runtime->refunds()->recoverReviewed((string) $this->option('reference'), (string) $this->option('case'), (string) $this->option('actor'), (string) $this->option('evidence'))],
                'compensation-status' => $this->compensationStatus($runtime),
                'exhausted' => ['items' => $runtime->store()->exhaustedItems((string) $this->option('queue'), $limit, $this->option('reference') === null ? null : (string) $this->option('reference'))],
                'replay-exhausted' => $runtime->store()->replayExhausted((string) $this->option('queue'), (string) $this->option('reference'), (string) $this->option('actor'), (string) $this->option('evidence'), (string) $this->option('key')),
                'metrics' => $runtime->store()->metrics($stale),
                'acknowledge-compensation' => $runtime->compensations()->acknowledge((string) $this->option('reference'), (string) $this->option('actor'), (string) $this->option('evidence'), (string) $this->option('key')),
                default => throw new PaymentError('invalid_action', 422),
            };
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } catch (PaymentError $error) {
            $this->error($error->reason);
        } catch (\Throwable) {
            $this->error('payment_unavailable');
        }
        return self::FAILURE;
    }

    private function compensationStatus(PaymentRuntime $runtime): array
    {
        $intent = $runtime->store()->intent((string) $this->option('reference')) ?? throw new PaymentError('not_found', 404);
        return ['reference' => $intent['reference'], 'financialState' => $intent['state'], 'fulfillmentState' => $intent['fulfillment'], 'compensationState' => $intent['compensation'] ?? 'not_requested', 'acknowledgement' => $intent['compensation_ack'] ?? null, 'acknowledgedAt' => $intent['compensation_acknowledged_at'] ?? null];
    }

    private function work(PaymentRuntime $runtime, int $limit): array
    {
        $worker = $runtime->worker();
        return ['inbox' => $worker->inbox($limit), 'reconciliation' => $worker->reconcile($limit), 'outbox' => $worker->outbox($limit)];
    }
}
