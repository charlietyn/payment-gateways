<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Support;

/** Case-insensitive header lookup over whatever a framework hands us. */
final class Headers
{
    /** @param  array<string, string|list<string>|null>  $headers */
    public static function get(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                $value = is_array($value) ? ($value[0] ?? null) : $value;

                return $value === null || $value === '' ? null : (string) $value;
            }
        }

        return null;
    }

    /**
     * `ts=1,v1=abc` / `t=1,v1=abc,v0=def` → ['ts' => '1', 'v1' => 'abc', ...]. Repeated keys keep all values.
     *
     * @return array<string, list<string>>
     */
    public static function parseSignature(string $header): array
    {
        $parts = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($key !== null && $key !== '' && $value !== null) {
                $parts[trim($key)][] = trim($value);
            }
        }

        return $parts;
    }
}
