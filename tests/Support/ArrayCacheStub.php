<?php

namespace LibreNMS\Plugins\WeatherRiskMap\Tests\Support;

use Closure;
use DateInterval;
use Illuminate\Contracts\Cache\Repository;

/**
 * Minimal in-memory cache repository for unit tests.
 *
 * Lets the cache and provider tests run without booting a Laravel container.
 * TTLs are intentionally ignored: tests drive expiry explicitly through
 * WeatherCacheService::forgetFresh() rather than sleeping.
 */
final class ArrayCacheStub implements Repository
{
    /** @var array<string, mixed> */
    private array $store = [];

    public function has($key): bool
    {
        return array_key_exists($key, $this->store);
    }

    public function missing($key): bool
    {
        return ! $this->has($key);
    }

    public function get($key, $default = null): mixed
    {
        if (array_key_exists($key, $this->store)) {
            return $this->store[$key];
        }

        return $default instanceof Closure ? $default() : $default;
    }

    public function many(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->get($key);
        }

        return $out;
    }

    public function pull($key, $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);

        return $value;
    }

    public function put($key, $value, $ttl = null): bool
    {
        $this->store[$key] = $value;

        return true;
    }

    public function putMany(array $values, $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->put($key, $value, $ttl);
        }

        return true;
    }

    public function add($key, $value, $ttl = null): bool
    {
        if ($this->has($key)) {
            return false;
        }

        return $this->put($key, $value, $ttl);
    }

    public function increment($key, $value = 1): int|bool
    {
        $this->store[$key] = ((int) ($this->store[$key] ?? 0)) + $value;

        return $this->store[$key];
    }

    public function decrement($key, $value = 1): int|bool
    {
        return $this->increment($key, -$value);
    }

    public function forever($key, $value): bool
    {
        return $this->put($key, $value);
    }

    public function remember($key, $ttl, Closure $callback): mixed
    {
        if ($this->has($key)) {
            return $this->get($key);
        }

        $value = $callback();
        $this->put($key, $value, $ttl);

        return $value;
    }

    public function sear($key, Closure $callback): mixed
    {
        return $this->rememberForever($key, $callback);
    }

    public function rememberForever($key, Closure $callback): mixed
    {
        return $this->remember($key, null, $callback);
    }

    public function touch($key, $ttl): bool
    {
        // This test store ignores TTLs, so only the key's existence matters.
        return $this->has($key);
    }

    public function forget($key): bool
    {
        unset($this->store[$key]);

        return true;
    }

    public function delete(string $key): bool
    {
        return $this->forget($key);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->forget($key);
        }

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->get($key, $default);
        }

        return $out;
    }

    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->put($key, $value, $ttl);
        }

        return true;
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        return $this->put($key, $value, $ttl);
    }

    public function clear(): bool
    {
        $this->store = [];

        return true;
    }

    public function getStore()
    {
        return null;
    }
}
