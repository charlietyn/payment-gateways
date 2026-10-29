<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Support;

final class SensitiveData
{
    /**
     * @template TKey of array-key
     * @param array<TKey, mixed> $payload
     * @return array<TKey, mixed>
     */
    public static function redact(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (preg_match('/^(token|access_token|refresh_token|client_secret|secret_key|api_key|authorization|password|cvv|cvc|card_number|number)$/i', (string) $key)) {
                unset($payload[$key]);
            } elseif (is_array($value)) {
                $payload[$key] = self::redact($value);
            }
        }
        return $payload;
    }
}
