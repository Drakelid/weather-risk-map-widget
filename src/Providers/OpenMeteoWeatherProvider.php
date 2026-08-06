<?php

namespace LibreNMS\Plugins\WeatherRiskMap\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use LibreNMS\Plugins\WeatherRiskMap\Contracts\WeatherProviderInterface;
use LibreNMS\Plugins\WeatherRiskMap\DTO\Coordinate;
use LibreNMS\Plugins\WeatherRiskMap\DTO\Forecast;
use LibreNMS\Plugins\WeatherRiskMap\DTO\ForecastPoint;
use LibreNMS\Plugins\WeatherRiskMap\Services\WeatherCacheService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Open-Meteo - open-meteo.com
 *
 * Secondary provider. Two reasons it exists:
 *
 *   1. Its forecast endpoint accepts comma-separated coordinate lists, so all
 *      geo cells are retrieved in ONE request instead of one per cell.
 *   2. It gives worldwide coverage for installations with devices outside
 *      MET Norway's warning area.
 *
 * It publishes no official warnings, so supportsWarnings() is false and the
 * orchestrator keeps using MET Norway for those.
 *
 * Free for non-commercial use, no API key. Commercial deployments should
 * check open-meteo.com/en/pricing before selecting this provider.
 */
class OpenMeteoWeatherProvider implements WeatherProviderInterface
{
    private const FORECAST_URL = 'https://api.open-meteo.com/v1/forecast';

    private const HOURLY_VARIABLES = [
        'temperature_2m',
        'relative_humidity_2m',
        'precipitation',
        'wind_speed_10m',
        'wind_gusts_10m',
        'weather_code',
    ];

    /** Batch size: keeps the query string well inside proxy URL limits. */
    private const MAX_COORDINATES_PER_REQUEST = 50;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly WeatherCacheService $cache,
        private readonly LoggerInterface $logger,
        private readonly string $userAgent,
        private readonly int $forecastTtlSeconds = 900,
        private readonly int $timeoutSeconds = 8,
        private readonly int $forecastDays = 3,
    ) {}

    public function key(): string
    {
        return 'open-meteo';
    }

    public function name(): string
    {
        return 'Open-Meteo';
    }

    public function attribution(): string
    {
        return 'Weather data from Open-Meteo.com (CC BY 4.0)';
    }

    public function supportsWarnings(): bool
    {
        return false;
    }

    public function covers(Coordinate $coordinate): bool
    {
        return true;
    }

    public function getWarnings(): array
    {
        return [];
    }

    public function getForecast(Coordinate $coordinate): ?Forecast
    {
        $key = $coordinate->forApi()->key();

        return $this->getForecasts([$key => $coordinate])[$key] ?? null;
    }

    public function getForecasts(array $coordinates): array
    {
        if ($coordinates === []) {
            return [];
        }

        $out = [];
        $pending = [];

        // Serve whatever is already cached, and collect the rest for batching.
        foreach ($coordinates as $cellId => $coordinate) {
            $api = $coordinate->forApi();
            $cacheKey = $this->key() . ':forecast:' . $api->key();

            if ($this->cache->hasFresh($cacheKey)) {
                $cached = $this->cache->stale($cacheKey);
                if (is_array($cached['value'])) {
                    $hydrated = $this->hydratePoint($cached['value'], $api, $cached['fetched_at']);
                    if ($hydrated !== null) {
                        $out[$cellId] = $hydrated;

                        continue;
                    }
                }
            }

            $pending[$cellId] = $api;
        }

        foreach (array_chunk($pending, self::MAX_COORDINATES_PER_REQUEST, preserve_keys: true) as $chunk) {
            $out += $this->fetchBatch($chunk);
        }

        return $out;
    }

    /**
     * @param  array<string, Coordinate>  $chunk
     * @return array<string, Forecast>
     */
    private function fetchBatch(array $chunk): array
    {
        $cellIds = array_keys($chunk);
        $coordinates = array_values($chunk);

        try {
            $response = $this->http
                ->withHeaders(['User-Agent' => $this->userAgent, 'Accept' => 'application/json'])
                ->timeout($this->timeoutSeconds)
                ->connectTimeout(min(5, $this->timeoutSeconds))
                ->retry(1, 250, throw: false)
                ->get(self::FORECAST_URL, [
                    'latitude' => implode(',', array_map(fn (Coordinate $c) => $c->lat, $coordinates)),
                    'longitude' => implode(',', array_map(fn (Coordinate $c) => $c->lng, $coordinates)),
                    'hourly' => implode(',', self::HOURLY_VARIABLES),
                    'timezone' => 'UTC',
                    'forecast_days' => $this->forecastDays,
                    'wind_speed_unit' => 'ms',
                ]);
        } catch (Throwable $e) {
            $this->logger->warning('weather-risk-map: Open-Meteo request failed', [
                'count' => count($chunk),
                'message' => $e->getMessage(),
            ]);

            return $this->staleFallback($chunk);
        }

        if ($response->status() === 429) {
            $this->logger->error('weather-risk-map: Open-Meteo rate limited', [
                'hint' => 'Increase the forecast cache TTL or reduce geo-cell count.',
            ]);

            return $this->staleFallback($chunk);
        }

        if (! $response->successful()) {
            $this->logger->warning('weather-risk-map: Open-Meteo returned an error status', [
                'status' => $response->status(),
            ]);

            return $this->staleFallback($chunk);
        }

        try {
            $json = $response->json();
        } catch (Throwable $e) {
            $this->logger->warning('weather-risk-map: malformed JSON from Open-Meteo', [
                'message' => $e->getMessage(),
            ]);

            return $this->staleFallback($chunk);
        }

        // A single-coordinate query returns one object; multi-coordinate
        // returns a list. Normalise so downstream code sees one shape.
        if (! is_array($json)) {
            return $this->staleFallback($chunk);
        }
        $entries = array_is_list($json) ? $json : [$json];

        if (count($entries) !== count($cellIds)) {
            $this->logger->warning('weather-risk-map: Open-Meteo returned an unexpected number of locations', [
                'requested' => count($cellIds),
                'received' => count($entries),
            ]);
        }

        $now = CarbonImmutable::now();
        $out = [];

        foreach ($entries as $i => $entry) {
            if (! isset($cellIds[$i]) || ! is_array($entry)) {
                continue;
            }

            $cellId = $cellIds[$i];
            $coordinate = $chunk[$cellId];

            $forecast = $this->hydratePoint($entry, $coordinate, $now);
            if ($forecast === null) {
                continue;
            }

            $this->cache->remember(
                $this->key() . ':forecast:' . $coordinate->key(),
                $this->forecastTtlSeconds,
                fn () => $entry,
            );

            $out[$cellId] = $forecast;
        }

        // Anything the batch did not yield still deserves stale data.
        $missing = array_diff_key($chunk, $out);

        return $out + $this->staleFallback($missing);
    }

    /**
     * @param  array<string, Coordinate>  $chunk
     * @return array<string, Forecast>
     */
    private function staleFallback(array $chunk): array
    {
        $out = [];

        foreach ($chunk as $cellId => $coordinate) {
            $cached = $this->cache->stale($this->key() . ':forecast:' . $coordinate->key());
            if (! is_array($cached['value'])) {
                continue;
            }

            $forecast = $this->hydratePoint($cached['value'], $coordinate, $cached['fetched_at']);
            if ($forecast !== null) {
                $out[$cellId] = $forecast;
            }
        }

        return $out;
    }

    /**
     * Convert one Open-Meteo location object into our DTO.
     *
     * Public for fixture-based unit testing.
     *
     * @param  array<string, mixed>  $entry
     */
    public function hydratePoint(array $entry, Coordinate $coordinate, ?CarbonImmutable $fetchedAt = null): ?Forecast
    {
        $hourly = $entry['hourly'] ?? null;
        if (! is_array($hourly) || ! isset($hourly['time']) || ! is_array($hourly['time'])) {
            return null;
        }

        $times = $hourly['time'];
        $points = [];

        foreach ($times as $i => $time) {
            if (! is_string($time)) {
                continue;
            }

            try {
                $parsed = CarbonImmutable::parse($time, 'UTC');
            } catch (Throwable) {
                continue;
            }

            $code = $this->valueAt($hourly, 'weather_code', $i);

            $points[] = new ForecastPoint(
                time: $parsed,
                temperature: $this->valueAt($hourly, 'temperature_2m', $i),
                windSpeed: $this->valueAt($hourly, 'wind_speed_10m', $i),
                windGust: $this->valueAt($hourly, 'wind_gusts_10m', $i),
                precipitation: $this->valueAt($hourly, 'precipitation', $i),
                humidity: $this->valueAt($hourly, 'relative_humidity_2m', $i),
                symbolCode: $code === null ? null : $this->wmoToSymbol((int) $code),
            );
        }

        if ($points === []) {
            return null;
        }

        return new Forecast(
            coordinate: $coordinate,
            points: $points,
            fetchedAt: $fetchedAt ?? CarbonImmutable::now(),
            provider: $this->key(),
        );
    }

    /** @param array<string, mixed> $hourly */
    private function valueAt(array $hourly, string $variable, int $index): ?float
    {
        $series = $hourly[$variable] ?? null;
        if (! is_array($series) || ! array_key_exists($index, $series)) {
            return null;
        }

        return is_numeric($series[$index]) ? (float) $series[$index] : null;
    }

    /**
     * Translate WMO weather codes into MET-Norway-style symbol strings, so the
     * risk engine has one vocabulary to reason about regardless of provider.
     *
     * WMO 4677 codes as documented at open-meteo.com/en/docs.
     */
    private function wmoToSymbol(int $code): string
    {
        return match (true) {
            $code === 0 => 'clearsky',
            $code <= 3 => 'cloudy',
            $code === 45 || $code === 48 => 'fog',
            $code >= 51 && $code <= 57 => 'lightrain',
            $code >= 61 && $code <= 65 => 'rain',
            $code === 66 || $code === 67 => 'sleet',      // freezing rain
            $code >= 71 && $code <= 77 => 'snow',
            $code >= 80 && $code <= 82 => 'heavyrain',
            $code === 85 || $code === 86 => 'snow',
            $code >= 95 => 'rainandthunder',
            default => 'cloudy',
        };
    }
}
