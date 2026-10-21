<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Tests\Unit;

use DateInterval;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;

/** A PSR-16 cache in memory, shared by the gateways a test builds. `broken` throws on every call. */
final class ArrayCache implements CacheInterface
{
    /** @var array<string, array{mixed, ?int}> */
    public array $items = [];

    public function __construct(private bool $broken = false) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $this->guard();
        [$value, $expires] = $this->items[$key] ?? [$default, null];

        return $expires !== null && $expires <= time() ? $default : $value;
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->guard();
        $this->items[$key] = [$value, is_int($ttl) ? time() + $ttl : null];

        return true;
    }

    public function delete(string $key): bool
    {
        $this->guard();
        unset($this->items[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->items = [];

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        foreach ($keys as $key) {
            yield $key => $this->get($key, $default);
        }
    }

    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return $this->get($key, $this) !== $this;
    }

    private function guard(): void
    {
        if ($this->broken) {
            throw new RuntimeException('cache down');
        }
    }
}
