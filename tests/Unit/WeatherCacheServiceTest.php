<?php

namespace LibreNMS\Plugins\WeatherRiskMap\Tests\Unit;

use LibreNMS\Plugins\WeatherRiskMap\Services\WeatherCacheService;
use LibreNMS\Plugins\WeatherRiskMap\Tests\Support\ArrayCacheStub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Graceful degradation: the specification requires that a provider failure
 * never empties the widget when usable cached data exists.
 */
final class WeatherCacheServiceTest extends TestCase
{
    private function service(int $backoff = 60): WeatherCacheService
    {
        return new WeatherCacheService(new ArrayCacheStub(), new NullLogger(), 360, $backoff);
    }

    public function test_a_fresh_value_is_returned_and_marked_not_stale(): void
    {
        $cache = $this->service();

        $result = $cache->remember('k', 900, fn () => ['data' => 1]);

        $this->assertSame(['data' => 1], $result['value']);
        $this->assertFalse($result['stale']);
    }

    public function test_a_cached_value_is_served_without_calling_the_resolver_again(): void
    {
        $cache = $this->service();
        $calls = 0;

        $resolver = function () use (&$calls) {
            $calls++;

            return ['data' => $calls];
        };

        $cache->remember('k', 900, $resolver);
        $second = $cache->remember('k', 900, $resolver);

        $this->assertSame(1, $calls, 'the second call must hit the cache');
        $this->assertSame(['data' => 1], $second['value']);
    }

    public function test_a_thrown_resolver_falls_back_to_the_stale_copy(): void
    {
        $cache = $this->service(backoff: 0);

        $cache->remember('k', 900, fn () => ['data' => 'good']);
        $cache->forgetFresh('k');

        $result = $cache->remember('k', 900, function () {
            throw new RuntimeException('provider down');
        });

        $this->assertSame(['data' => 'good'], $result['value'], 'stale data must survive an outage');
        $this->assertTrue($result['stale']);
        $this->assertNotNull($result['fetched_at']);
    }

    public function test_a_null_resolver_result_falls_back_to_the_stale_copy(): void
    {
        $cache = $this->service(backoff: 0);

        $cache->remember('k', 900, fn () => ['data' => 'good']);
        $cache->forgetFresh('k');

        $result = $cache->remember('k', 900, fn () => null);

        $this->assertSame(['data' => 'good'], $result['value']);
        $this->assertTrue($result['stale']);
    }

    public function test_with_no_cached_data_a_failure_yields_null_rather_than_throwing(): void
    {
        $cache = $this->service();

        $result = $cache->remember('cold-start', 900, function () {
            throw new RuntimeException('provider down');
        });

        $this->assertNull($result['value']);
        $this->assertTrue($result['stale']);
    }

    public function test_a_failed_provider_is_not_retried_during_the_backoff(): void
    {
        $cache = $this->service(backoff: 60);
        $calls = 0;

        $failing = function () use (&$calls) {
            $calls++;

            throw new RuntimeException('down');
        };

        $cache->remember('k', 900, $failing);
        $cache->remember('k', 900, $failing);
        $cache->remember('k', 900, $failing);

        $this->assertSame(1, $calls, 'backoff must prevent hammering a dead provider');
    }

    public function test_a_successful_fetch_clears_the_backoff(): void
    {
        $cache = $this->service(backoff: 60);

        $cache->remember('k', 900, fn () => throw new RuntimeException('down'));
        $cache->forgetFresh('k');

        // Backoff is active, so this is served from (absent) stale data.
        $this->assertNull($cache->remember('k', 900, fn () => ['ok' => true])['value']);

        // Clearing the failure marker lets the next attempt through.
        $cache->forget('k');
        $this->assertSame(['ok' => true], $cache->remember('k', 900, fn () => ['ok' => true])['value']);
    }

    public function test_metadata_round_trips(): void
    {
        $cache = $this->service();

        $cache->putMeta('k', ['last_modified' => 'Wed, 06 Aug 2026 12:00:00 GMT'], 3600);

        $this->assertSame('Wed, 06 Aug 2026 12:00:00 GMT', $cache->getMeta('k')['last_modified']);
        $this->assertNull($cache->getMeta('missing'));
    }

    public function test_forget_removes_every_tier(): void
    {
        $cache = $this->service();

        $cache->remember('k', 900, fn () => ['data' => 1]);
        $cache->forget('k');

        $this->assertFalse($cache->hasFresh('k'));
        $this->assertNull($cache->stale('k')['value']);
    }
}
