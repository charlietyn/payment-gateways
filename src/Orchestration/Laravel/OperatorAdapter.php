<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration\Laravel;

use Illuminate\Http\Request;
use Ronu\PaymentGateways\Orchestration\Operator;

/**
 * Project-owned: who is operating, for which project and tenant, on which accounts, with which
 * capabilities (0.10.0). Called on EVERY admin request; it must authorize unconditionally (a global
 * "permissions disabled" switch must never turn into "every authenticated user may refund") and throw
 * PaymentError('forbidden', 403) when the user may not operate at all. Never read the project, tenant
 * or accounts from the request.
 */
interface OperatorAdapter
{
    public function operator(Request $request): Operator;
}
