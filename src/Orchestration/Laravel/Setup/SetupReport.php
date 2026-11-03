<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration\Laravel\Setup;

final class SetupReport
{
    /** @var list<array{step:string,status:string,reason:string}> */
    private array $steps = [];

    public function add(string $step, string $status, string $reason = ''): void
    {
        $this->steps[] = compact('step', 'status', 'reason');
    }

    /** @return list<array{step:string,status:string,reason:string}> */
    public function steps(): array
    {
        return $this->steps;
    }

    public function failed(): bool
    {
        return in_array('failed', array_column($this->steps, 'status'), true);
    }
}
