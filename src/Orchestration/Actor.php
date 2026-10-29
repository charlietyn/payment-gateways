<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration;

final readonly class Actor
{
    /** @param list<string> $accounts @param list<string> $capabilities */
    public function __construct(public string $project, public string $tenant, public string $owner, public array $accounts, public array $capabilities)
    {
        foreach ([$project, $tenant, $owner] as $id) {
            if ($id === '' || strlen($id) > 128) {
                throw new PaymentError('invalid_actor', 403);
            }
        }
    }

    public function scope(): string
    {
        return hash('sha256', json_encode([$this->project, $this->tenant, $this->owner], JSON_THROW_ON_ERROR));
    }

    public function require(string $capability, string $account): void
    {
        if (!in_array($capability, $this->capabilities, true) || !in_array($account, $this->accounts, true)) {
            throw new PaymentError('forbidden', 403);
        }
    }
}
