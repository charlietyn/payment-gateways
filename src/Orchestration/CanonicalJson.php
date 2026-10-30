<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration;

final class CanonicalJson
{
    /** @param array<mixed> $data */
    public static function encode(array $data): string
    {
        return json_encode(self::normalize($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function normalize(array $data, int $depth = 0): array
    {
        if ($depth > 12 || count($data) > 256) {
            throw new PaymentError('invalid_payload', 422);
        }
        if (!array_is_list($data)) {
            ksort($data, SORT_STRING);
        }
        foreach ($data as $key => $value) {
            if (in_array($key, ['__proto__', 'constructor', 'prototype'], true) || is_float($value) || is_object($value) || is_resource($value)) {
                throw new PaymentError('invalid_payload', 422);
            }
            if (is_array($value)) {
                $data[$key] = self::normalize($value, $depth + 1);
            }
        }
        return $data;
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }
}
