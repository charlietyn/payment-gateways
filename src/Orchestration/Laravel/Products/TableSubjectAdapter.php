<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration\Laravel\Products;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Ronu\PaymentGateways\Money;
use Ronu\PaymentGateways\Orchestration\Actor;
use Ronu\PaymentGateways\Orchestration\Contracts\ManualCompensation;
use Ronu\PaymentGateways\Orchestration\Laravel\ProductAdapter;
use Ronu\PaymentGateways\Orchestration\Laravel\Products\Events\PaymentSubjectCompensationAcknowledged;
use Ronu\PaymentGateways\Orchestration\Laravel\Products\Events\PaymentSubjectCompensationRequested;
use Ronu\PaymentGateways\Orchestration\Laravel\Products\Events\PaymentSubjectDelivered;
use Ronu\PaymentGateways\Orchestration\Laravel\Products\Events\PaymentSubjectDisputed;
use Ronu\PaymentGateways\Orchestration\Laravel\Products\Events\PaymentSubjectEvent;
use Ronu\PaymentGateways\Orchestration\Laravel\Products\Events\PaymentSubjectRefunded;
use Ronu\PaymentGateways\Orchestration\Laravel\Products\Events\PaymentSubjectRejected;
use Ronu\PaymentGateways\Orchestration\PaymentError;
use Ronu\PaymentGateways\Orchestration\Quote;
use Ronu\PaymentGateways\Orchestration\SubjectAttempt;

/**
 * Pays one row of the product's own table, configured in `payments.product` (see ProductConfiguration).
 *
 * The product creates the row WITH its price; this adapter reads it, lets the orchestration charge it and,
 * once the money is captured, marks it paid. Whatever else must happen (grant access, ship, email) is a
 * listener of the events in `Events\`, dispatched after commit and at least once.
 *
 * Two tables of the project back it, on the same connection as the subject so every decision is atomic
 * with the row it changes:
 *  - receipts:      event_id (unique), subject, accepted (bool), reason (nullable), created_at
 *  - notifications: event_id (unique), subject, type, observation_version, minor (nullable),
 *                   currency (nullable), state (nullable), created_at
 */
final class TableSubjectAdapter implements ProductAdapter, ManualCompensation
{
    private ?ProductConfiguration $configuration = null;

    /** @var list<string> */
    private array $problems = [];

    public function __construct(private Container $container)
    {
    }

    public function ready(): bool
    {
        $configuration = $this->parsed();
        if ($configuration === null) {
            return $this->notReady($this->problems);
        }
        $schema = $this->db()->getSchemaBuilder();
        $problems = [];
        foreach ([$configuration->table, $configuration->receiptsTable, $configuration->notificationsTable] as $table) {
            if (!$schema->hasTable($table)) {
                $problems[] = "table $table does not exist";
            }
        }
        if ($problems === []) {
            foreach ($configuration->columns() as $column) {
                if (!$schema->hasColumn($configuration->table, $column)) {
                    $problems[] = "column {$configuration->table}.$column does not exist";
                }
            }
        }
        return $problems === [] ? true : $this->notReady($problems);
    }

    public function actor(Request $request): Actor
    {
        $resolver = $this->container->make($this->configuration()->actorResolver);
        if (!$resolver instanceof ActorResolver) {
            throw new PaymentError('adapter_not_ready', 503);
        }
        return $resolver->actor($request);
    }

    public function resolve(Actor $actor, string $subject): Quote
    {
        $c = $this->configuration();
        $row = $this->owned($this->rows(), $actor, $subject)->first();
        if ($row === null) {
            // Foreign and missing answer alike: a reference alone must not reveal a subject.
            throw new PaymentError('not_found', 404);
        }
        $row = (array) $row;
        $amount = $this->amount($row);
        $expiresAt = $this->expiresAt($row);
        if (!$this->payable($row) || $expiresAt <= time()) {
            throw new PaymentError('subject_unavailable', 409);
        }
        $description = $c->descriptionColumn !== null ? trim((string) ($row[$c->descriptionColumn] ?? '')) : '';
        return new Quote(
            subject: $subject,
            revision: $this->revision($row, $amount),
            // Authorization was decided above by the ownership strategy; the payer is the actor.
            ownerScope: $actor->scope(),
            amount: $amount,
            expiresAt: $expiresAt,
            shared: $c->shared(),
            retryable: $c->retries,
            description: $description === '' ? null : mb_substr($description, 0, 256),
        );
    }

    public function deliver(string $eventId, string $subject, array $payment): bool
    {
        $c = $this->configuration();
        [$accepted, $reason, $replayed] = $this->db()->transaction(function () use ($c, $eventId, $subject, $payment): array {
            $row = $this->lockedRow($subject);
            $receipt = $this->db()->table($c->receiptsTable)->where('event_id', $eventId)->first();
            if ($receipt !== null) {
                if ($receipt->subject !== $subject) {
                    throw new PaymentError('receipt_conflict', 409);
                }
                return [(bool) $receipt->accepted, $receipt->reason, true];
            }
            $reason = $this->deliveryRefusal($row, $payment);
            if ($reason === null) {
                $changes = [$c->statusColumn => $c->paid];
                if ($c->paidAtColumn !== null) {
                    $changes[$c->paidAtColumn] = self::now();
                }
                $this->rows()->where($c->reference, SubjectAttempt::base($subject))->update($changes);
            }
            $this->db()->table($c->receiptsTable)->insert([
                'event_id' => $eventId, 'subject' => $subject, 'accepted' => $reason === null, 'reason' => $reason, 'created_at' => self::now(),
            ]);
            return [$reason === null, $reason, false];
        });
        // Replays dispatch again: listeners are idempotent by eventId, and a crash after commit must not lose them.
        unset($replayed);
        $this->dispatch($accepted
            ? new PaymentSubjectDelivered($eventId, SubjectAttempt::base($subject), SubjectAttempt::number($subject), $payment)
            : new PaymentSubjectRejected($eventId, SubjectAttempt::base($subject), SubjectAttempt::number($subject), $payment, (string) $reason));
        return $accepted;
    }

    public function compensate(string $eventId, string $subject, array $payment): void
    {
        $this->notify($eventId, $subject, 'compensation_required', 0, $payment);
        $this->dispatch(new PaymentSubjectCompensationRequested($eventId, SubjectAttempt::base($subject), SubjectAttempt::number($subject), $payment));
    }

    public function acknowledgeCompensation(string $receiptId, string $subject, array $acknowledgement): void
    {
        $this->notify($receiptId, $subject, 'compensation_acknowledged', 0, []);
        $this->dispatch(new PaymentSubjectCompensationAcknowledged($receiptId, SubjectAttempt::base($subject), SubjectAttempt::number($subject), $acknowledgement));
    }

    public function consume(string $eventId, string $type, array $payload): void
    {
        $subject = (string) $payload['subject'];
        $version = (int) $payload['observationVersion'];
        if ($type === 'payment.refunded') {
            $refunded = $this->notify($eventId, $subject, $type, $version, $payload);
            $this->dispatch(new PaymentSubjectRefunded($eventId, SubjectAttempt::base($subject), SubjectAttempt::number($subject), $payload, $refunded['minor'], $refunded['full']));
            return;
        }
        if ($type === 'payment.dispute_updated') {
            $this->notify($eventId, $subject, $type, $version, $payload);
            $this->dispatch(new PaymentSubjectDisputed($eventId, SubjectAttempt::base($subject), SubjectAttempt::number($subject), $payload, (string) $payload['state']));
            return;
        }
        throw new PaymentError('unsupported_event', 503);
    }

    /**
     * Records a notification once (by event id) and projects refunds and disputes onto the status column,
     * ignoring an observation older than one already applied (events may arrive out of order).
     *
     * @param array<string, mixed> $payload
     * @return array{minor: int, full: bool}
     */
    private function notify(string $eventId, string $subject, string $type, int $version, array $payload): array
    {
        $c = $this->configuration();
        return $this->db()->transaction(function () use ($c, $eventId, $subject, $type, $version, $payload): array {
            $row = $this->lockedRow($subject);
            $notifications = $this->db()->table($c->notificationsTable);
            $existing = (clone $notifications)->where('event_id', $eventId)->first();
            if ($existing !== null) {
                if ($existing->subject !== $subject || $existing->type !== $type || (int) $existing->observation_version !== $version) {
                    throw new PaymentError('receipt_conflict', 409);
                }
            } else {
                (clone $notifications)->insert([
                    'event_id' => $eventId, 'subject' => $subject, 'type' => $type, 'observation_version' => $version,
                    'minor' => isset($payload['minor']) ? (int) $payload['minor'] : null,
                    'currency' => $payload['currency'] ?? null, 'state' => $payload['state'] ?? null, 'created_at' => self::now(),
                ]);
            }
            $refundedMinor = (int) (clone $notifications)->where('subject', $subject)->where('type', 'payment.refunded')->sum('minor');
            $paidMinor = $this->amount($row)->minor;
            $full = $refundedMinor >= $paidMinor;
            $newest = (int) (clone $notifications)->where('subject', $subject)->whereIn('type', ['payment.refunded', 'payment.dispute_updated'])->max('observation_version');
            if ($existing === null && $version >= $newest) {
                $this->project($row, $type, $payload, $full);
            }
            return ['minor' => $refundedMinor, 'full' => $full];
        });
    }

    /**
     * Moves the status only from a status the payment itself set: a product that already moved the row
     * elsewhere (cancelled, archived) keeps its decision.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $payload
     */
    private function project(array $row, string $type, array $payload, bool $full): void
    {
        $c = $this->configuration();
        $current = (string) ($row[$c->statusColumn] ?? '');
        if (!in_array($current, array_filter([$c->paid, $c->refunded, $c->partiallyRefunded, $c->disputed]), true)) {
            return;
        }
        $next = match (true) {
            $type === 'payment.refunded' => $full ? $c->refunded : $c->partiallyRefunded,
            $type === 'payment.dispute_updated' && in_array($payload['state'] ?? null, ['opened', 'lost'], true) => $c->disputed,
            $type === 'payment.dispute_updated' && ($payload['state'] ?? null) === 'won' && $current === $c->disputed => $c->paid,
            default => null,
        };
        if ($next !== null && $next !== $current) {
            $this->rows()->where($c->reference, $row[$c->reference])->update([$c->statusColumn => $next]);
        }
    }

    /**
     * Why a captured payment cannot be delivered, or null when it can.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $payment
     */
    private function deliveryRefusal(array $row, array $payment): ?string
    {
        $c = $this->configuration();
        $amount = $this->amount($row);
        return match (true) {
            ($row[$c->statusColumn] ?? null) === $c->paid => 'already_paid',
            !$this->payable($row) => 'not_payable',
            (string) $amount->minor !== (string) ($payment['minor'] ?? '') || $amount->currency !== ($payment['currency'] ?? '') => 'amount_changed',
            !$c->acceptLateDelivery && $this->expiresAt($row) <= time() => 'expired',
            default => null,
        };
    }

    /** @param array<string, mixed> $row */
    private function payable(array $row): bool
    {
        $c = $this->configuration();
        return in_array((string) ($row[$c->statusColumn] ?? ''), $c->payable, true)
            && ($c->deliverableColumn === null || (bool) ($row[$c->deliverableColumn] ?? false));
    }

    /** @param array<string, mixed> $row */
    private function amount(array $row): Money
    {
        $c = $this->configuration();
        $currency = $c->currencyFixed ?? strtoupper(trim((string) ($row[(string) $c->currencyColumn] ?? '')));
        $raw = $row[$c->amountColumn] ?? null;
        try {
            if (!preg_match('/^[A-Z]{3}$/D', $currency)) {
                throw new PaymentError('invalid_quote', 422);
            }
            if ($c->amountUnit === 'minor') {
                $minor = filter_var($raw, FILTER_VALIDATE_INT);
                if ($minor === false || $minor < 1) {
                    throw new PaymentError('invalid_quote', 422);
                }
                return new Money($minor, $currency);
            }
            // A float column is refused by the generator; a driver may still hand a decimal back as string.
            if (!is_string($raw) && !is_int($raw)) {
                throw new PaymentError('invalid_quote', 422);
            }
            $money = Money::fromDecimal((string) $raw, $currency);
            if ($money->minor < 1) {
                throw new PaymentError('invalid_quote', 422);
            }
            return $money;
        } catch (PaymentError $error) {
            throw $error;
        } catch (\Throwable) {
            throw new PaymentError('invalid_quote', 422);
        }
    }

    /** @param array<string, mixed> $row */
    private function expiresAt(array $row): int
    {
        $c = $this->configuration();
        if ($c->ttlMinutes !== null) {
            return time() + $c->ttlMinutes * 60;
        }
        $value = $row[(string) $c->expiresColumn] ?? null;
        $time = $value === null ? false : strtotime((string) $value);
        if ($time === false) {
            throw new PaymentError('invalid_quote', 422);
        }
        return $time;
    }

    /** @param array<string, mixed> $row */
    private function revision(array $row, Money $amount): string
    {
        $c = $this->configuration();
        if ($c->revisionColumn !== null) {
            $revision = trim((string) ($row[$c->revisionColumn] ?? ''));
            if ($revision === '' || strlen($revision) > 128) {
                throw new PaymentError('invalid_quote', 422);
            }
            return $revision;
        }
        // No revision column: the price itself is the revision, so a changed price changes it.
        return substr(hash('sha256', $amount->minor.'|'.$amount->currency), 0, 16);
    }

    /**
     * Adds the ownership filter of the configured strategy to a query of the subject.
     */
    private function owned(\Illuminate\Database\Query\Builder $query, Actor $actor, string $subject): \Illuminate\Database\Query\Builder
    {
        $c = $this->configuration();
        if ($c->strategy === 'link' && mb_strlen($subject) < ProductConfiguration::LINK_MIN_REFERENCE) {
            // A guessable reference is not a capability: never resolve it.
            $query->whereRaw('1 = 0');
        }
        $query->where($c->reference, $subject);
        if ($c->strategy === 'owner') {
            $query->where((string) $c->ownerColumn, $actor->scope());
        }
        if ($c->tenantColumn !== null) {
            $query->where($c->tenantColumn, $actor->tenant);
        }
        return $query;
    }

    /** @return array<string, mixed> */
    private function lockedRow(string $subject): array
    {
        $row = $this->rows()->where($this->configuration()->reference, SubjectAttempt::base($subject))->lockForUpdate()->first();
        if ($row === null) {
            // Never delete a subject that has payments: this is retried and reported, never silently dropped.
            throw new PaymentError('subject_missing', 503);
        }
        return (array) $row;
    }

    private function rows(): \Illuminate\Database\Query\Builder
    {
        $c = $this->configuration();
        $query = $this->db()->table($c->table);
        if ($c->softDeleteColumn !== null) {
            $query->whereNull($c->softDeleteColumn);
        }
        return $query;
    }

    private function db(): Connection
    {
        $parsed = $this->parsed();
        $connection = $parsed !== null && $parsed->connection !== null ? $parsed->connection : $this->config()->get('payments.connection');
        $db = $this->container->make(DatabaseManager::class)->connection(is_string($connection) ? $connection : null);
        return $db instanceof Connection ? $db : throw new PaymentError('adapter_not_ready', 503);
    }

    /** UTC wall clock for receipts; no framework facade, so the adapter runs outside a full Laravel app too. */
    private static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    private function dispatch(PaymentSubjectEvent $event): void
    {
        $this->container->make(Dispatcher::class)->dispatch($event);
    }

    private function configuration(): ProductConfiguration
    {
        return $this->parsed() ?? throw new PaymentError('adapter_not_ready', 503);
    }

    /** The configuration, or null with `problems` filled; parsed once per instance. */
    private function parsed(): ?ProductConfiguration
    {
        if ($this->configuration === null && $this->problems === []) {
            $raw = $this->config()->get('payments.product');
            [$this->configuration, $this->problems] = is_array($raw) ? ProductConfiguration::parse($raw) : [null, ['payments.product is missing']];
        }
        return $this->configuration;
    }

    private function config(): Config
    {
        return $this->container->make(Config::class);
    }

    /** @param list<string> $problems */
    private function notReady(array $problems): bool
    {
        if ($this->container->bound(LoggerInterface::class)) {
            $this->container->make(LoggerInterface::class)->error('payments: product adapter not ready', ['problems' => $problems]);
        }
        return false;
    }
}
