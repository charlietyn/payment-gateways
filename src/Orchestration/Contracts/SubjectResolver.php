<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration\Contracts;

use Ronu\PaymentGateways\Orchestration\{Actor, Quote};

interface SubjectResolver
{
    /** Resolve the authoritative price and ownership; never accept buyer-supplied Money. */
    public function resolve(Actor $actor, string $subject): Quote;
}
