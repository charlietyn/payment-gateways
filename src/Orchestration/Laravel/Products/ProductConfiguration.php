<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration\Laravel\Products;

/**
 * The validated `payments.product` block of a TableSubjectAdapter.
 *
 * A subject is one row of the product's own table, priced BEFORE it is paid. This class only reads and
 * checks the declaration; it never touches the database. Every problem is collected, so a misconfigured
 * project is told everything at once (TableSubjectAdapter::ready() logs them and the API answers 503).
 */
final readonly class ProductConfiguration
{
    public const STRATEGIES = ['owner', 'tenant', 'link'];

    /** A link-owned subject is a capability: its reference must not be guessable. */
    public const LINK_MIN_REFERENCE = 16;

    /**
     * @param list<string> $payable
     */
    private function __construct(
        public ?string $connection,
        public string $table,
        public string $reference,
        public string $amountColumn,
        public string $amountUnit,
        public ?string $currencyColumn,
        public ?string $currencyFixed,
        public string $strategy,
        public ?string $ownerColumn,
        public ?string $tenantColumn,
        public string $statusColumn,
        public array $payable,
        public string $paid,
        public ?string $refunded,
        public ?string $partiallyRefunded,
        public ?string $disputed,
        public ?string $deliverableColumn,
        public ?string $expiresColumn,
        public ?int $ttlMinutes,
        public bool $acceptLateDelivery,
        public ?string $revisionColumn,
        public ?string $descriptionColumn,
        public ?string $paidAtColumn,
        public ?string $softDeleteColumn,
        public bool $retries,
        public string $receiptsTable,
        public string $notificationsTable,
        public string $actorResolver,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{0: ?self, 1: list<string>}  The configuration, or null and every problem found.
     */
    public static function parse(array $raw): array
    {
        $problems = [];
        // Closures by reference: an arrow function would collect the problems into a copy.
        $table = function (mixed $v, string $at) use (&$problems): ?string {
            return self::table($v, $at, $problems);
        };
        $column = function (mixed $v, string $at, bool $required = true) use (&$problems): ?string {
            return self::column($v, $at, $required, $problems);
        };

        $amount = is_array($raw['amount'] ?? null) ? $raw['amount'] : [];
        $currency = is_array($raw['currency'] ?? null) ? $raw['currency'] : [];
        $ownership = is_array($raw['ownership'] ?? null) ? $raw['ownership'] : [];
        $tenant = is_array($raw['tenant'] ?? null) ? $raw['tenant'] : [];
        $status = is_array($raw['status'] ?? null) ? $raw['status'] : [];
        $expires = is_array($raw['expires'] ?? null) ? $raw['expires'] : [];

        $unit = $amount['unit'] ?? null;
        if (!in_array($unit, ['minor', 'decimal'], true)) {
            $problems[] = 'amount.unit must be minor or decimal';
        }
        $currencyColumn = isset($currency['column']) ? $column($currency['column'], 'currency.column') : null;
        $currencyFixed = $currency['fixed'] ?? null;
        if (($currencyColumn === null) === ($currencyFixed === null)) {
            $problems[] = 'currency needs exactly one of column or fixed';
        } elseif ($currencyFixed !== null && (!is_string($currencyFixed) || !preg_match('/^[A-Z]{3}$/D', $currencyFixed))) {
            $problems[] = 'currency.fixed must be a three-letter code';
            $currencyFixed = null;
        }

        $strategy = $ownership['strategy'] ?? null;
        if (!in_array($strategy, self::STRATEGIES, true)) {
            $problems[] = 'ownership.strategy must be one of '.implode(', ', self::STRATEGIES);
        }
        $ownerColumn = $strategy === 'owner' ? $column($ownership['column'] ?? null, 'ownership.column') : null;
        $tenantColumn = isset($tenant['column']) ? $column($tenant['column'], 'tenant.column') : null;
        if ($strategy === 'tenant' && $tenantColumn === null) {
            $problems[] = 'ownership.strategy tenant needs tenant.column';
        }

        $payable = $status['payable'] ?? null;
        if (!is_array($payable) || $payable === [] || array_filter($payable, fn ($v) => !is_string($v) || $v === '') !== []) {
            $problems[] = 'status.payable must be a non-empty list of values';
            $payable = [];
        }
        $value = function (string $key, bool $required) use ($status, &$problems): ?string {
            $v = $status[$key] ?? null;
            if ($v === null && !$required) {
                return null;
            }
            if (!is_string($v) || $v === '' || strlen($v) > 64) {
                $problems[] = "status.$key must be a value of the status column";
                return null;
            }
            return $v;
        };
        $paid = $value('paid', true);
        $refunded = $value('refunded', false);
        $partially = $value('partiallyRefunded', false);
        $disputed = $value('disputed', false);
        foreach (array_filter([$paid, $refunded, $partially, $disputed]) as $after) {
            if (in_array($after, $payable, true)) {
                $problems[] = "status value '$after' cannot be both payable and a result of paying";
            }
        }

        $expiresColumn = isset($expires['column']) ? $column($expires['column'], 'expires.column') : null;
        $ttl = $expires['ttlMinutes'] ?? null;
        if (($expiresColumn === null) === ($ttl === null)) {
            $problems[] = 'expires needs exactly one of column or ttlMinutes';
        } elseif ($ttl !== null && (!is_int($ttl) || $ttl < 1 || $ttl > 60 * 24 * 90)) {
            $problems[] = 'expires.ttlMinutes must be between 1 and 129600';
            $ttl = null;
        }
        $late = $expires['lateDelivery'] ?? 'reject';
        if (!in_array($late, ['reject', 'accept'], true)) {
            $problems[] = 'expires.lateDelivery must be reject or accept';
        }

        $resolver = $raw['actorResolver'] ?? null;
        if (!is_string($resolver) || !preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $resolver)) {
            $problems[] = 'actorResolver must be a class name';
        }
        $retries = $raw['retries'] ?? true;
        if (!is_bool($retries)) {
            $problems[] = 'retries must be true or false';
        }
        $connection = $raw['connection'] ?? null;
        if ($connection !== null && (!is_string($connection) || $connection === '')) {
            $problems[] = 'connection must be a connection name';
        }

        $configuration = [
            'connection' => $connection,
            'table' => $table($raw['table'] ?? null, 'table'),
            'reference' => $column($raw['reference'] ?? null, 'reference'),
            'amountColumn' => $column($amount['column'] ?? null, 'amount.column'),
            'statusColumn' => $column($status['column'] ?? null, 'status.column'),
            'deliverableColumn' => $column($raw['deliverable']['column'] ?? null, 'deliverable.column', false),
            'revisionColumn' => $column($raw['revision']['column'] ?? null, 'revision.column', false),
            'descriptionColumn' => $column($raw['description']['column'] ?? null, 'description.column', false),
            'paidAtColumn' => $column($raw['paidAt']['column'] ?? null, 'paidAt.column', false),
            'softDeleteColumn' => $column($raw['softDelete']['column'] ?? null, 'softDelete.column', false),
            'receiptsTable' => $table($raw['receipts']['table'] ?? null, 'receipts.table'),
            'notificationsTable' => $table($raw['notifications']['table'] ?? null, 'notifications.table'),
        ];
        if ($problems !== [] || in_array(null, [$configuration['table'], $configuration['reference'], $configuration['amountColumn'], $configuration['statusColumn'], $configuration['receiptsTable'], $configuration['notificationsTable'], $paid], true)) {
            return [null, $problems === [] ? ['incomplete product configuration'] : $problems];
        }
        return [new self(
            $connection,
            $configuration['table'],
            $configuration['reference'],
            $configuration['amountColumn'],
            $unit,
            $currencyColumn,
            $currencyFixed,
            $strategy,
            $ownerColumn,
            $tenantColumn,
            $configuration['statusColumn'],
            array_values($payable),
            $paid,
            $refunded,
            $partially,
            $disputed,
            $configuration['deliverableColumn'],
            $expiresColumn,
            $ttl,
            $late === 'accept',
            $configuration['revisionColumn'],
            $configuration['descriptionColumn'],
            $configuration['paidAtColumn'],
            $configuration['softDeleteColumn'],
            $retries,
            $configuration['receiptsTable'],
            $configuration['notificationsTable'],
            ltrim($resolver, '\\'),
        ), []];
    }

    /** More than one actor may pay the same subject. */
    public function shared(): bool
    {
        return $this->strategy !== 'owner';
    }

    /** @return list<string> Every product column the adapter reads or writes. */
    public function columns(): array
    {
        return array_values(array_unique(array_filter([
            $this->reference, $this->amountColumn, $this->currencyColumn, $this->ownerColumn, $this->tenantColumn,
            $this->statusColumn, $this->deliverableColumn, $this->expiresColumn, $this->revisionColumn,
            $this->descriptionColumn, $this->paidAtColumn, $this->softDeleteColumn,
        ])));
    }

    /** @param list<string> $problems */
    private static function table(mixed $value, string $at, array &$problems): ?string
    {
        if (!is_string($value) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}(\.[A-Za-z_][A-Za-z0-9_]{0,62})?$/D', $value)) {
            $problems[] = "$at must be a table name (optionally schema.table)";
            return null;
        }
        return $value;
    }

    /** @param list<string> $problems */
    private static function column(mixed $value, string $at, bool $required, array &$problems): ?string
    {
        if ($value === null && !$required) {
            return null;
        }
        if (!is_string($value) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/D', $value)) {
            $problems[] = "$at must be a column name";
            return null;
        }
        return $value;
    }
}
