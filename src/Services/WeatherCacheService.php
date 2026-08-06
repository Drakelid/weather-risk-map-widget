<?php

namespace LibreNMS\Plugins\WeatherRiskMap\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Two-tier cache for external weather data.
 *
 * Every entry is written twice:
 *
 *   fresh:<key>  - short TTL, the normal serving copy
 *   stale:<key>  - long TTL, the fallback used when the provider is down
 *
 * This is what lets the widget satisfy "weather API failures must never break
 * the dashboard". When a fetch fails we serve the stale copy and label it,
 * rather than showing an empty map. A single-tier cache cannot do this: the
 * moment the TTL expires the data is gone precisely when the provider is
 * unreachable and we need it most.
 *
 * A negative-result marker additionally prevents a hard-down provider from
 * being hammered once per dashboard refresh by every logged-in NOC operator.
 */
class WeatherCacheService
{
    private const PREFIX = 'wxrisk';

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly LoggerInterface $logger,
        private readonly int $staleTtlMinutes = 360,
        private readonly int $failureBackoffSeconds = 60,
    ) {}

    /**
     * Serve from cache, otherwise call $resolver and cache its result.
     *
     * @template T
     *
     * @param  callable(): ?T  $resolver
     * @return array{value: ?T, stale: bool, age_minutes: ?int, fetched_at: ?CarbonImmutable}
     */
    public function remember(string $key, int $ttlSeconds, callable $resolver): array
    {
        $fresh = $this->read(self::PREFIX . ':fresh:' . $key);
        if ($fresh !== null) {
            return $this->result($fresh, stale: false);
        }

        // Do not retry a provider that just failed; serve stale immediately.
        if ($this->isBackedOff($key)) {
            return $this->result($this->read(self::PREFIX . ':stale:' . $key), stale: true);
        }

        try {
            $value = $resolver();
        } catch (Throwable $e) {
            $this->logger->warning('weather-risk-map: provider fetch failed, falling back to cache', [
                'key' => $key,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $this->markFailure($key);

            return $this->result($this->read(self::PREFIX . ':stale:' . $key), stale: true);
        }

        if ($value === null) {
            $this->markFailure($key);

            return $this->result($this->read(self::PREFIX . ':stale:' . $key), stale: true);
        }

        $envelope = [
            'value' => $value,
            'fetched_at' => CarbonImmutable::now()->toIso8601String(),
        ];

        $this->write(self::PREFIX . ':fresh:' . $key, $envelope, $ttlSeconds);
        $this->write(self::PREFIX . ':stale:' . $key, $envelope, $this->staleTtlMinutes * 60);
        $this->cache->forget(self::PREFIX . ':fail:' . $key);

        return $this->result($envelope, stale: false);
    }

    /**
     * Read the stale copy without attempting a fetch. Used by diagnostics and
     * by callers that have already decided not to hit the network.
     *
     * @return array{value: mixed, stale: bool, age_minutes: ?int, fetched_at: ?CarbonImmutable}
     */
    public function stale(string $key): array
    {
        return $this->result($this->read(self::PREFIX . ':stale:' . $key), stale: true);
    }

    public function forget(string $key): void
    {
        $this->cache->forget(self::PREFIX . ':fresh:' . $key);
        $this->cache->forget(self::PREFIX . ':stale:' . $key);
        $this->cache->forget(self::PREFIX . ':fail:' . $key);
    }

    /**
     * Expire only the serving copy, keeping the stale fallback.
     *
     * Equivalent to the short TTL lapsing. Used by the diagnose command to
     * force a real fetch, and by tests to exercise the fallback path without
     * waiting for a real TTL.
     */
    public function forgetFresh(string $key): void
    {
        $this->cache->forget(self::PREFIX . ':fresh:' . $key);
    }

    public function hasFresh(string $key): bool
    {
        return $this->read(self::PREFIX . ':fresh:' . $key) !== null;
    }

    /**
     * Small opaque store used for HTTP revalidation metadata (Last-Modified,
     * Expires). Kept separate from the payload cache so a payload eviction
     * cannot leave us sending a stale If-Modified-Since.
     */
    public function putMeta(string $key, array $meta, int $ttlSeconds): void
    {
        $this->write(self::PREFIX . ':meta:' . $key, $meta, $ttlSeconds);
    }

    public function getMeta(string $key): ?array
    {
        $value = $this->read(self::PREFIX . ':meta:' . $key);

        return is_array($value) ? $value : null;
    }

    private function isBackedOff(string $key): bool
    {
        return $this->cache->has(self::PREFIX . ':fail:' . $key);
    }

    private function markFailure(string $key): void
    {
        $this->write(self::PREFIX . ':fail:' . $key, true, $this->failureBackoffSeconds);
    }

    private function read(string $key): mixed
    {
        try {
            return $this->cache->get($key);
        } catch (Throwable $e) {
            // A broken cache backend must not take the dashboard down with it.
            $this->logger->error('weather-risk-map: cache read failed', [
                'key' => $key,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function write(string $key, mixed $value, int $ttlSeconds): void
    {
        try {
            $this->cache->put($key, $value, $ttlSeconds);
        } catch (Throwable $e) {
            $this->logger->error('weather-risk-map: cache write failed', [
                'key' => $key,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{value: mixed, stale: bool, age_minutes: ?int, fetched_at: ?CarbonImmutable}
     */
    private function result(mixed $envelope, bool $stale): array
    {
        if (! is_array($envelope) || ! array_key_exists('value', $envelope)) {
            return ['value' => null, 'stale' => $stale, 'age_minutes' => null, 'fetched_at' => null];
        }

        $fetchedAt = null;
        if (! empty($envelope['fetched_at'])) {
            try {
                $fetchedAt = CarbonImmutable::parse($envelope['fetched_at']);
            } catch (Throwable) {
                $fetchedAt = null;
            }
        }

        return [
            'value' => $envelope['value'],
            'stale' => $stale,
            'age_minutes' => $fetchedAt ? (int) $fetchedAt->diffInMinutes(CarbonImmutable::now(), true) : null,
            'fetched_at' => $fetchedAt,
        ];
    }
}
