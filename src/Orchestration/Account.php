<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration;

use Ronu\PaymentGateways\Contracts\Gateway;

final readonly class Account
{
    /**
     * Accepted combinations, all local emulator only: Plaza (Mercado Pago Orders), Vector (Stripe
     * PaymentIntents) and Harbor (PayPal Orders v2). Only currencies the emulator accepts and with two
     * decimals are declared; zero- and three-decimal currencies stay out until a provider admits them.
     */
    public const CURRENCIES = ['mercadopago' => ['ARS'], 'stripe' => ['ARS', 'USD', 'EUR'], 'paypal' => ['USD', 'EUR']];

    /** The API family is part of the identity; Mercado Pago keeps 'orders' so existing identities never change. */
    public const API_FAMILIES = ['mercadopago' => 'orders', 'stripe' => 'payment_intents', 'paypal' => 'orders_v2'];

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
        if ($environment !== 'emulator' || !in_array($currency, self::CURRENCIES[$gateway->name()] ?? [], true)) {
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
        return hash('sha256', json_encode([$this->alias, $this->gateway->name(), self::API_FAMILIES[$this->gateway->name()], $this->merchant, $this->environment, $this->currency], JSON_THROW_ON_ERROR));
    }
}
