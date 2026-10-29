<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration\Laravel;

use Illuminate\Http\Request;
use Ronu\PaymentGateways\Orchestration\Actor;
use Ronu\PaymentGateways\Orchestration\Contracts\{SubjectResolver, Fulfillment, FinancialEventConsumer};

/** Product-owned authorization and business effects. No financial writes belong here. */
interface ProductAdapter extends SubjectResolver, Fulfillment, FinancialEventConsumer
{
    public function actor(Request $request): Actor;
    public function ready(): bool;
}
