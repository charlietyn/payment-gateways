<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration;

/**
 * Payment attempts of one product subject.
 *
 * The first attempt keeps the subject as the product named it, so existing data never changes. When a
 * retryable subject's last attempt ended WITHOUT money (declined or canceled), the next one is stored as
 * `<subject>#<n>`. The `#` never comes from outside: a retryable quote refuses a requested subject that
 * contains it.
 */
final class SubjectAttempt
{
    /** The subject the product knows, without the attempt suffix. */
    public static function base(string $subject): string
    {
        return preg_match('/^(.+)#([2-9]|[1-9][0-9]{1,3})$/D', $subject, $match) === 1 ? $match[1] : $subject;
    }

    /** 1 for the bare subject, n for `<subject>#<n>`. */
    public static function number(string $subject): int
    {
        return preg_match('/#([2-9]|[1-9][0-9]{1,3})$/D', $subject, $match) === 1 ? (int) $match[1] : 1;
    }

    public static function next(string $base, int $current): string
    {
        if ($current >= 9999 || strlen($base) + 6 > 128) {
            throw new PaymentError('attempts_exhausted', 409);
        }
        return $base.'#'.($current + 1);
    }

    /**
     * An attempt that ended without moving money may be followed by another one. Anything else (pending,
     * uncertain, paid, refunded, disputed) keeps the subject reserved.
     *
     * @param array<string, mixed> $intent
     */
    public static function endedWithoutMoney(array $intent): bool
    {
        return in_array($intent['state'] ?? null, ['failed', 'canceled'], true)
            && ($intent['captured_minor'] ?? null) === '0'
            && ($intent['refunded_minor'] ?? null) === '0'
            && in_array($intent['fulfillment'] ?? null, ['pending', null], true);
    }
}
