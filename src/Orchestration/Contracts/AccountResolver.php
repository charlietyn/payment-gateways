<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration\Contracts;

use Ronu\PaymentGateways\Orchestration\{Actor, Account};

interface AccountResolver
{
    /** New requests use the default ONLY inside the actor's account allowlist. */
    public function forNew(Actor $actor, ?string $alias): Account;
    /** Must preserve historical merchant identity even after a default/credential rotation. */
    public function historical(string $alias, string $identity): Account;
}
