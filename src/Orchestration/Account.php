<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration;

use Ronu\PaymentGateways\Contracts\Gateway;

final readonly class Account
{
    /**
     * @param list<string> $actionOrigins
     * @param Gateway|null $previousWebhookGateway verifies with the rotated-out webhook secret only
     *                                             until $previousWebhookUntil (Unix seconds)
     */
    public function __construct(public string $alias, public string $merchant, public string $environment, public string $currency, public int $configurationVersion, public Gateway $gateway, public array $actionOrigins, public bool $acceptNew = false, public ?Gateway $previousWebhookGateway = null, public ?int $previousWebhookUntil = null)
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,62}$/D', $alias) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $merchant) || $configurationVersion < 1) {
            throw new PaymentError('invalid_account', 503);
        }
        // A rotation window is finite by construction: an old secret never comes without its end.
        if (($previousWebhookGateway === null) !== ($previousWebhookUntil === null)
            || ($previousWebhookGateway !== null && $previousWebhookGateway->name() !== $gateway->name())) {
            throw new PaymentError('invalid_webhook_rotation', 503);
        }
        if ($environment !== 'emulator' || $currency !== 'ARS' || $gateway->name() !== 'mercadopago') {
            throw new PaymentError('unsupported_account', 503);
        }
    }

    /**
     * Verifiers for an incoming webhook: the current secret first, the previous one while its window is open.
     *
     * @return list<\Ronu\PaymentGateways\Contracts\Gateway>
     */
    public function webhookVerifiers(int $now): array
    {
        return $this->previousWebhookGateway !== null && $now < $this->previousWebhookUntil
            ? [$this->gateway, $this->previousWebhookGateway]
            : [$this->gateway];
    }

    public function identity(): string
    {
        return hash('sha256', json_encode([$this->alias, $this->gateway->name(), 'orders', $this->merchant, $this->environment, $this->currency], JSON_THROW_ON_ERROR));
    }
}
