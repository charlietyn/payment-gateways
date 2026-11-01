<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration;

use Ronu\PaymentGateways\CheckoutStatus;
use Ronu\PaymentGateways\Contracts\HostedCheckout;
use Ronu\PaymentGateways\CreatePayment;
use Ronu\PaymentGateways\Money;
use Ronu\PaymentGateways\NextAction;
use Ronu\PaymentGateways\Payment;
use Ronu\PaymentGateways\PaymentStatus;

/**
 * The one place that knows an account's API family changes what its provider id names.
 *
 * Orders, PaymentIntents and Orders v2 store the PAYMENT as provider id, so every read is
 * `getPayment(provider_id)`. Checkout Pro stores the PREFERENCE: the payment appears only when the
 * buyer attempts it, under a different (numeric) id found through the preference. Every reader
 * goes through here so that difference cannot leak into one call site and be forgotten in another.
 *
 * What comes back always carries the intent's provider id as `id` (the identity the orchestration
 * checks) and the provider's payment id as `transactionId` (what a refund targets).
 */
final class ProviderView
{
    /** Creates the provider resource for a new intent. */
    public static function create(Account $account, CreatePayment $request): Payment
    {
        if ($account->apiFamily !== 'checkout_pro') {
            return $account->gateway->createPayment($request);
        }
        $checkout = self::hosted($account)->createCheckout($request);
        return self::waiting($account, $checkout->id, $request->amount, $request->reference, $checkout->url, $checkout->returnsToMerchant);
    }

    /** The current payment behind an intent's provider id, or null when the provider has no such resource. */
    public static function payment(Account $account, string $providerId, Money $amount, string $reference): ?Payment
    {
        if ($account->apiFamily !== 'checkout_pro') {
            return $account->gateway->getPayment($providerId);
        }
        $checkout = self::hosted($account)->getCheckout($providerId);
        if ($checkout === null) {
            return null;
        }
        if ($checkout->paymentId === null) {
            // Nobody has paid yet: the page is still the next step, or it expired unpaid.
            return $checkout->status === CheckoutStatus::Expired
                ? new Payment($account->gateway->name(), $providerId, PaymentStatus::Canceled, $amount, $checkout->reference, 'expired')
                : self::waiting($account, $providerId, $amount, $checkout->reference ?? $reference, $checkout->url, $checkout->returnsToMerchant);
        }
        $paid = $account->gateway->getPayment($checkout->paymentId);
        return $paid === null ? null : self::asIntentIdentity($paid, $providerId);
    }

    /**
     * A webhook names the PAYMENT. For Checkout Pro that is not the intent's provider id, so the
     * resource is resolved to its intent through the payment's external reference and read again
     * through the preference.
     */
    public static function paymentForEvent(Account $account, string $resourceId): ?Payment
    {
        return $account->gateway->getPayment($resourceId);
    }

    /** The provider id a refund targets: the payment, which for Checkout Pro is not the preference. */
    public static function refundTarget(Account $account, string $providerId): string
    {
        if ($account->apiFamily !== 'checkout_pro') {
            return $providerId;
        }
        $checkout = self::hosted($account)->getCheckout($providerId);
        if ($checkout === null || $checkout->paymentId === null) {
            throw new PaymentError('refund_target_missing', 409);
        }
        return $checkout->paymentId;
    }

    private static function hosted(Account $account): HostedCheckout
    {
        if (!$account->gateway instanceof HostedCheckout) {
            throw new PaymentError('unsupported_account', 503);
        }
        return $account->gateway;
    }

    private static function waiting(Account $account, string $providerId, Money $amount, ?string $reference, ?string $url, bool $returnsToMerchant): Payment
    {
        return new Payment(
            gateway: $account->gateway->name(),
            id: $providerId,
            status: PaymentStatus::RequiresAction,
            amount: $amount,
            reference: $reference,
            providerStatus: 'open',
            nextAction: $url !== null ? NextAction::redirect($url, $returnsToMerchant) : null,
        );
    }

    private static function asIntentIdentity(Payment $paid, string $providerId): Payment
    {
        return new Payment(
            gateway: $paid->gateway,
            id: $providerId,
            status: $paid->status,
            amount: $paid->amount,
            reference: $paid->reference,
            providerStatus: $paid->providerStatus,
            providerStatusDetail: $paid->providerStatusDetail,
            transactionId: $paid->id,
            capturedAmount: $paid->capturedAmount,
            refundedAmount: $paid->refundedAmount,
            nextAction: $paid->nextAction,
            raw: $paid->raw,
        );
    }
}
