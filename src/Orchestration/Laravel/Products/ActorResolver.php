<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration\Laravel\Products;

use Illuminate\Http\Request;
use Ronu\PaymentGateways\Orchestration\Actor;

/**
 * Who is paying: the CLIENT APPLICATION authenticated on the request (a BFF, a mobile app, a POS), not a
 * person. The project supplies it (the generator emits one over laravel-app-context), so this library does
 * not depend on any particular authentication package.
 */
interface ActorResolver
{
    public function actor(Request $request): Actor;
}
