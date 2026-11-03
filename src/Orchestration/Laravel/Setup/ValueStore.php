<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration\Laravel\Setup;

/** Puerto para .env o un almacén externo de secretos (por ejemplo Floci). */
interface ValueStore
{
    /** @return array<string, string> */
    public function read(): array;

    /** @param array<string, string> $values */
    public function write(array $values): string;
}
