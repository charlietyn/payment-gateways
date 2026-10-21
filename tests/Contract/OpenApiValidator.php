<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Tests\Contract;

/**
 * Checks a request body against an operation's requestBody schema in an official
 * OpenAPI document. Covers the subset the provider specs use: $ref, allOf / anyOf /
 * oneOf, type (+ nullable), enum, properties, required, additionalProperties, items,
 * maxLength.
 *
 * Form bodies (Stripe) arrive as strings: with $coerce, "1" is an integer and
 * "true" a boolean. Mercado Pago's spec writes enum values as {title, description}:
 * the title is the value.
 */
final class OpenApiValidator
{
    /** @var array<string, mixed> */
    private array $spec;

    public function __construct(string $file)
    {
        ini_set('memory_limit', '1G');
        $spec = json_decode((string) file_get_contents($file), true, 4096, JSON_THROW_ON_ERROR);
        $this->spec = is_array($spec) ? $spec : [];
    }

    /** The spec path template a concrete path matches: /v1/orders/ORD1/refund → /v1/orders/{order_id}/refund. */
    public function template(string $method, string $path): ?string
    {
        foreach ((array) ($this->spec['paths'] ?? []) as $template => $operations) {
            $regex = '#^' . preg_replace('#\\\{[^/]+\\\}#', '[^/]+', preg_quote((string) $template, '#')) . '$#';
            if (preg_match($regex, $path) && isset($operations[strtolower($method)])) {
                return (string) $template;
            }
        }

        return null;
    }

    /**
     * @return list<string>  Empty when the body fits.
     */
    public function validate(string $method, string $template, string $contentType, mixed $body): array
    {
        $operation = $this->spec['paths'][$template][strtolower($method)] ?? null;
        $content = $this->resolve($operation['requestBody'] ?? [])['content'] ?? [];
        $schema = $content[$contentType]['schema'] ?? null;
        if ($schema === null) {
            return $body === [] || $body === null ? [] : ["{$method} {$template} takes no {$contentType} body"];
        }

        return $this->check($body, $schema, '$', $contentType === 'application/x-www-form-urlencoded');
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function check(mixed $value, array $schema, string $at, bool $coerce, int $depth = 0, bool $open = false): array
    {
        $schema = $this->resolve($schema);
        if ($depth > 64) {
            return [];
        }

        if ($value === null && ! empty($schema['nullable'])) {
            return [];
        }

        if (isset($schema['allOf'])) {
            // Each part declares some of the properties: unknown keys are judged on the union.
            $errors = [];
            $known = [];
            $closed = false;
            foreach ($schema['allOf'] as $part) {
                $errors = [...$errors, ...$this->check($value, $part, $at, $coerce, $depth + 1, open: true)];
                $part = $this->resolve($part);
                $known = [...$known, ...array_keys((array) ($part['properties'] ?? []))];
                $closed = $closed || ($part['additionalProperties'] ?? true) === false;
            }
            if ($closed && is_array($value) && ! array_is_list($value)) {
                foreach (array_diff(array_keys($value), $known) as $key) {
                    $errors[] = "{$at}.{$key} is not allowed";
                }
            }

            return $errors;
        }

        foreach (['anyOf', 'oneOf'] as $keyword) {
            if (isset($schema[$keyword])) {
                $all = [];
                foreach ($schema[$keyword] as $option) {
                    $errors = $this->check($value, $option, $at, $coerce, $depth + 1);
                    if ($errors === []) {
                        return [];
                    }
                    $all[] = implode('; ', $errors);
                }

                return ["{$at}: matches none of {$keyword} (" . implode(' | ', array_slice($all, 0, 3)) . ')'];
            }
        }

        $type = $schema['type'] ?? (isset($schema['properties']) ? 'object' : null);
        $error = $type !== null ? $this->typeError($value, (string) $type, $at, $coerce) : null;
        if ($error !== null) {
            return [$error];
        }

        if (isset($schema['enum'])) {
            $allowed = array_map(fn ($v) => is_array($v) && isset($v['title']) ? $v['title'] : $v, $schema['enum']);
            if (! in_array($value, $allowed, false)) {
                return ["{$at}: " . json_encode($value) . ' is not one of ' . json_encode(array_slice($allowed, 0, 12))];
            }
        }

        if (is_string($value) && isset($schema['maxLength']) && mb_strlen($value) > (int) $schema['maxLength']) {
            return ["{$at}: longer than {$schema['maxLength']}"];
        }

        $errors = [];
        if ($type === 'object' && is_array($value)) {
            $properties = (array) ($schema['properties'] ?? []);
            foreach ((array) ($schema['required'] ?? []) as $required) {
                if (! array_key_exists($required, $value)) {
                    $errors[] = "{$at}.{$required} is required";
                }
            }
            foreach ($value as $key => $item) {
                if (isset($properties[$key])) {
                    $errors = [...$errors, ...$this->check($item, $properties[$key], "{$at}.{$key}", $coerce, $depth + 1)];
                } elseif (! $open && ($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = "{$at}.{$key} is not allowed";
                } elseif (is_array($schema['additionalProperties'] ?? null)) {
                    $errors = [...$errors, ...$this->check($item, $schema['additionalProperties'], "{$at}.{$key}", $coerce, $depth + 1)];
                }
            }
        }

        if ($type === 'array' && is_array($value) && isset($schema['items'])) {
            foreach (array_values($value) as $i => $item) {
                $errors = [...$errors, ...$this->check($item, $schema['items'], "{$at}[{$i}]", $coerce, $depth + 1)];
            }
        }

        return $errors;
    }

    private function typeError(mixed $value, string $type, string $at, bool $coerce): ?string
    {
        $ok = match ($type) {
            'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
            'array' => is_array($value) && ($value === [] || array_is_list($value)),
            'string' => is_string($value),
            'integer' => is_int($value) || ($coerce && is_string($value) && preg_match('/^-?\d+$/', $value) === 1),
            'number' => is_int($value) || is_float($value) || ($coerce && is_string($value) && is_numeric($value)),
            'boolean' => is_bool($value) || ($coerce && in_array($value, ['true', 'false'], true)),
            default => true,
        };

        return $ok ? null : "{$at}: expected {$type}, got " . json_encode($value);
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function resolve(array $node): array
    {
        $guard = 0;
        while (isset($node['$ref']) && is_string($node['$ref']) && $guard++ < 32) {
            $target = $this->spec;
            foreach (explode('/', substr($node['$ref'], 2)) as $segment) {
                $target = $target[str_replace(['~1', '~0'], ['/', '~'], $segment)] ?? [];
            }
            $node = is_array($target) ? $target : [];
        }

        return $node;
    }
}
