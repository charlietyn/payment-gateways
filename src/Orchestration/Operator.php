<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration;

/**
 * An authenticated back-office user acting on payments of ONE project and tenant.
 *
 * It is deliberately not an Actor: an Actor is the buyer (or the partner client acting for one) and
 * its scope owns the payment. An operator never becomes that scope. It reads and operates on payments
 * of its project/tenant whose account it may access, and every write it causes keeps the payment's
 * original scope.
 *
 * `legacyHistory` grants visibility of intents created before intents recorded their project and
 * tenant (0.10.0). Such intents cannot be attributed to a tenant from storage, so seeing them is an
 * explicit grant of the consumer, never a default.
 */
final readonly class Operator
{
    /** read: list and detail. refund: operator refunds. recover: bring a pending reconciliation forward. */
    public const CAPABILITIES = ['read', 'refund', 'recover'];

    /**
     * @param list<string> $accounts
     * @param list<string> $capabilities
     */
    public function __construct(
        public string $project,
        public string $tenant,
        public string $id,
        public array $accounts,
        public array $capabilities,
        public bool $legacyHistory = false,
    ) {
        foreach ([$project, $tenant, $id] as $value) {
            if ($value === '' || strlen($value) > 128) {
                throw new PaymentError('invalid_operator', 403);
            }
        }
        foreach ($accounts as $alias) {
            if (!is_string($alias) || !preg_match('/^[a-z][a-z0-9_-]{0,62}$/D', $alias)) {
                throw new PaymentError('invalid_operator', 403);
            }
        }
        foreach ($capabilities as $capability) {
            if (!in_array($capability, self::CAPABILITIES, true)) {
                throw new PaymentError('invalid_operator', 403);
            }
        }
        if (!in_array('read', $capabilities, true)) {
            // Every operator action starts by reading the payment it acts on.
            throw new PaymentError('forbidden', 403);
        }
    }

    public function can(string $capability, string $account): bool
    {
        return in_array($capability, $this->capabilities, true) && in_array($account, $this->accounts, true);
    }

    public function require(string $capability, string $account): void
    {
        if (!$this->can($capability, $account)) {
            throw new PaymentError('forbidden', 403);
        }
    }

    /**
     * Whether a stored intent belongs to this operator's project/tenant and an account it may access.
     *
     * @param array<string, mixed> $intent
     */
    public function sees(array $intent): bool
    {
        if (!in_array($intent['account_alias'] ?? null, $this->accounts, true)) {
            return false;
        }
        if (!array_key_exists('project', $intent)) {
            return $this->legacyHistory;
        }
        return $intent['project'] === $this->project && ($intent['tenant'] ?? null) === $this->tenant;
    }

    /** Stable, non-reversible attribution for durable records (never the raw user id). */
    public function auditId(): string
    {
        return substr(hash('sha256', json_encode([$this->project, $this->tenant, $this->id], JSON_THROW_ON_ERROR)), 0, 32);
    }
}
