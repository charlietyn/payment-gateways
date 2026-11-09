<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration;

use Ronu\PaymentGateways\Contracts\Gateway;

final readonly class Account
{
    /**
     * Accepted combinations: Plaza (Mercado Pago Orders / Checkout Pro), Vector (Stripe PaymentIntents)
     * and Harbor (PayPal Orders v2) on the emulator; Mercado Pago Checkout Pro in sandbox and live
     * (ConfiguredAccounts enforces which provider/API may leave the emulator). Only currencies with two
     * decimals are declared; zero- and three-decimal currencies stay out until a provider admits them.
     */
    public const CURRENCIES = ['mercadopago' => ['ARS'], 'stripe' => ['ARS', 'USD', 'EUR'], 'paypal' => ['USD', 'EUR']];

    /** The API family is part of the identity; Mercado Pago keeps 'orders' so existing identities never change. */
    public const API_FAMILIES = ['mercadopago' => 'orders', 'stripe' => 'payment_intents', 'paypal' => 'orders_v2'];

    /**
     * Families an account may declare. Mercado Pago also offers Checkout Pro: a hosted preference whose
     * outcome is a Payments API payment. It is a different identity from 'orders' on purpose — the same
     * merchant on both APIs is two accounts, never one whose history changes meaning.
     */
    public const API_OPTIONS = ['mercadopago' => ['orders', 'checkout_pro'], 'stripe' => ['payment_intents'], 'paypal' => ['orders_v2']];

    /** Where an account's money moves. The environment is part of the identity: never one account's history in two. */
    public const ENVIRONMENTS = ['emulator', 'sandbox', 'live'];

    /** The family this account speaks: the declared one, or the gateway's default. */
    public string $apiFamily;

    /**
     * @param list<string> $actionOrigins
     * @param Gateway|null $previousWebhookGateway verifies with the rotated-out webhook secret only
     *                                             until $previousWebhookUntil (Unix seconds)
     */
    public function __construct(public string $alias, public string $merchant, public string $environment, public string $currency, public int $configurationVersion, public Gateway $gateway, public array $actionOrigins, public bool $acceptNew = false, public ?Gateway $previousWebhookGateway = null, public ?int $previousWebhookUntil = null, ?string $api = null)
    {
        $this->apiFamily = $api ?? (self::API_FAMILIES[$gateway->name()] ?? '');
        if (!preg_match('/^[a-z][a-z0-9_-]{0,62}$/D', $alias) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $merchant) || $configurationVersion < 1) {
            throw new PaymentError('invalid_account', 503);
        }
        // A rotation window is finite by construction: an old secret never comes without its end.
        if (($previousWebhookGateway === null) !== ($previousWebhookUntil === null)
            || ($previousWebhookGateway !== null && $previousWebhookGateway->name() !== $gateway->name())) {
            throw new PaymentError('invalid_webhook_rotation', 503);
        }
        if (!in_array($environment, self::ENVIRONMENTS, true) || !in_array($currency, self::CURRENCIES[$gateway->name()] ?? [], true)
            || !in_array($this->apiFamily, self::API_OPTIONS[$gateway->name()] ?? [], true)) {
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
        return hash('sha256', json_encode([$this->alias, $this->gateway->name(), $this->apiFamily, $this->merchant, $this->environment, $this->currency], JSON_THROW_ON_ERROR));
    }
}
