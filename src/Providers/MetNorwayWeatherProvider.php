<?php

namespace LibreNMS\Plugins\WeatherRiskMap\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use LibreNMS\Plugins\WeatherRiskMap\Contracts\WeatherProviderInterface;
use LibreNMS\Plugins\WeatherRiskMap\DTO\Coordinate;
use LibreNMS\Plugins\WeatherRiskMap\DTO\Forecast;
use LibreNMS\Plugins\WeatherRiskMap\DTO\ForecastPoint;
use LibreNMS\Plugins\WeatherRiskMap\DTO\WeatherWarning;
use LibreNMS\Plugins\WeatherRiskMap\Enums\HazardType;
use LibreNMS\Plugins\WeatherRiskMap\Enums\RiskLevel;
use LibreNMS\Plugins\WeatherRiskMap\Services\WeatherCacheService;
use LibreNMS\Plugins\WeatherRiskMap\Support\Polygon;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * MET Norway (Meteorologisk institutt) - api.met.no
 *
 * Two products are used:
 *
 *   Locationforecast 2.0 "compact" - point forecast, one request per geo cell.
 *   MetAlerts 2.0 "current.json"   - every active Norwegian warning as GeoJSON
 *                                    polygons, in a SINGLE request. Devices are
 *                                    then matched locally with point-in-polygon,
 *                                    which is why device count does not drive
 *                                    warning API traffic at all.
 *
 * Terms of service compliance (https://api.met.no/doc/TermsOfService):
 *   - identifying User-Agent with a contact email, else 403
 *   - coordinates truncated to at most 4 decimals, else 403
 *   - the Expires header is honoured; we never refetch before it
 *   - If-Modified-Since is sent using the exact previous Last-Modified value,
 *     and a 304 refreshes the TTL without re-downloading
 *   - 20 req/s application-wide; geo-cell grouping plus a per-refresh cap keep
 *     us orders of magnitude below this
 *
 * Data is licensed CC BY 4.0 and attribution is rendered in the widget footer.
 */
class MetNorwayWeatherProvider implements WeatherProviderInterface
{
    private const FORECAST_URL = 'https://api.met.no/weatherapi/locationforecast/2.0/compact';
    private const ALERTS_URL = 'https://api.met.no/weatherapi/metalerts/2.0/current.json';

    /**
     * MET Norway's warning coverage is Norway plus surrounding waters. Outside
     * this box we still return forecasts (the global model covers the world)
     * but callers can use covers() to prefer a local provider.
     */
    private const COVERAGE_BOX = ['minLat' => 54.0, 'maxLat' => 82.0, 'minLng' => -12.0, 'maxLng' => 42.0];

    public function __construct(
        private readonly HttpFactory $http,
        private readonly WeatherCacheService $cache,
        private readonly LoggerInterface $logger,
        private readonly string $userAgent,
        private readonly int $forecastTtlSeconds = 900,
        private readonly int $warningsTtlSeconds = 300,
        private readonly int $timeoutSeconds = 8,
        private readonly string $language = 'en',
    ) {}

    public function key(): string
    {
        return 'met-norway';
    }

    public function name(): string
    {
        return 'MET Norway';
    }

    public function attribution(): string
    {
        return 'Weather data from MET Norway (CC BY 4.0)';
    }

    public function supportsWarnings(): bool
    {
        return true;
    }

    public function covers(Coordinate $coordinate): bool
    {
        return $coordinate->lat >= self::COVERAGE_BOX['minLat']
            && $coordinate->lat <= self::COVERAGE_BOX['maxLat']
            && $coordinate->lng >= self::COVERAGE_BOX['minLng']
            && $coordinate->lng <= self::COVERAGE_BOX['maxLng'];
    }

    public function getForecast(Coordinate $coordinate): ?Forecast
    {
        $api = $coordinate->forApi();
        $cacheKey = $this->key() . ':forecast:' . $api->key();

        $result = $this->cache->remember(
            $cacheKey,
            $this->forecastTtlSeconds,
            fn () => $this->fetchForecastPayload($api, $cacheKey),
        );

        if (! is_array($result['value'])) {
            return null;
        }

        return $this->hydrateForecast($result['value'], $api, $result['fetched_at']);
    }

    public function getForecasts(array $coordinates): array
    {
        $out = [];

        // MET Norway has no bulk endpoint, so this is a loop by necessity.
        // The caller has already collapsed devices into a small number of
        // cells and caps how many uncached cells may be fetched per refresh.
        foreach ($coordinates as $cellId => $coordinate) {
            $forecast = $this->getForecast($coordinate);
            if ($forecast !== null) {
                $out[$cellId] = $forecast;
            }
        }

        return $out;
    }

    public function getWarnings(): array
    {
        $cacheKey = $this->key() . ':warnings:' . $this->language;

        $result = $this->cache->remember(
            $cacheKey,
            $this->warningsTtlSeconds,
            fn () => $this->fetchWarningsPayload($cacheKey),
        );

        if (! is_array($result['value'])) {
            return [];
        }

        $fetchedAt = $result['fetched_at'] ?? CarbonImmutable::now();

        return $this->hydrateWarnings($result['value'], $fetchedAt);
    }

    // ---------------------------------------------------------------- fetch

    /**
     * @return array<string, mixed>|null raw decoded JSON, or null to trigger
     *                                   the cache's stale fallback
     */
    private function fetchForecastPayload(Coordinate $api, string $cacheKey): ?array
    {
        if (($held = $this->heldByExpires($cacheKey)) !== null) {
            return $held;
        }

        $response = $this->request(self::FORECAST_URL, [
            'lat' => $api->lat,
            'lon' => $api->lng,
        ], $cacheKey);

        if ($response === null) {
            return null;
        }

        // 304: our cached body is still current. Returning it re-arms the
        // short TTL without transferring the payload again, exactly as the
        // terms of service ask.
        if ($response->status() === 304) {
            $cached = $this->cache->stale($cacheKey);

            return is_array($cached['value']) ? $cached['value'] : null;
        }

        $json = $this->decode($response, 'locationforecast');
        if ($json === null) {
            return null;
        }

        if (! isset($json['properties']['timeseries']) || ! is_array($json['properties']['timeseries'])) {
            $this->logger->warning('weather-risk-map: locationforecast response missing timeseries', [
                'coordinate' => $api->key(),
            ]);

            return null;
        }

        return $json;
    }

    /** @return array<string, mixed>|null */
    private function fetchWarningsPayload(string $cacheKey): ?array
    {
        if (($held = $this->heldByExpires($cacheKey)) !== null) {
            return $held;
        }

        $response = $this->request(self::ALERTS_URL, ['lang' => $this->language], $cacheKey);

        if ($response === null) {
            return null;
        }

        if ($response->status() === 304) {
            $cached = $this->cache->stale($cacheKey);

            return is_array($cached['value']) ? $cached['value'] : null;
        }

        $json = $this->decode($response, 'metalerts');
        if ($json === null) {
            return null;
        }

        // An empty FeatureCollection is a perfectly normal "no warnings today"
        // and must be cached as a success, not treated as a failure.
        if (! isset($json['features']) || ! is_array($json['features'])) {
            $this->logger->warning('weather-risk-map: metalerts response missing features array');

            return null;
        }

        return $json;
    }

    /**
     * Enforce the provider's own Expires header.
     *
     * MET Norway's terms of service say to respect Expires and "do not repeat
     * requests until the time indicated". Our configured TTL can be shorter
     * than the value MET returns (forecasts are typically valid ~30 minutes),
     * so without this check an aggressive TTL would make us non-compliant.
     *
     * Returns the cached payload when we are still inside the Expires window,
     * or null to mean "go ahead and request".
     *
     * @return array<string, mixed>|null
     */
    private function heldByExpires(string $cacheKey): ?array
    {
        $meta = $this->cache->getMeta($cacheKey);
        if ($meta === null || empty($meta['expires_at'])) {
            return null;
        }

        try {
            if (CarbonImmutable::parse($meta['expires_at'])->isPast()) {
                return null;
            }
        } catch (Throwable) {
            return null; // Unparseable Expires: treat as absent.
        }

        $cached = $this->cache->stale($cacheKey);

        // Only honour Expires while we actually still hold the body it
        // described; otherwise we would serve nothing at all.
        return is_array($cached['value']) ? $cached['value'] : null;
    }

    /**
     * Perform a GET with ToS-compliant headers and conditional revalidation.
     * Returns null on any transport/HTTP failure so the caller falls back.
     *
     * @param  array<string, mixed>  $query
     */
    private function request(string $url, array $query, string $cacheKey): ?Response
    {
        $meta = $this->cache->getMeta($cacheKey) ?? [];

        $headers = ['User-Agent' => $this->userAgent, 'Accept' => 'application/json'];
        if (! empty($meta['last_modified']) && is_string($meta['last_modified'])) {
            $headers['If-Modified-Since'] = $meta['last_modified'];
        }

        try {
            $response = $this->http
                ->withHeaders($headers)
                ->timeout($this->timeoutSeconds)
                ->connectTimeout(min(5, $this->timeoutSeconds))
                ->retry(2, 250, throw: false)
                ->get($url, $query);
        } catch (Throwable $e) {
            $this->logger->warning('weather-risk-map: MET Norway request failed', [
                'url' => $url,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        if ($response->status() === 304) {
            $this->storeMeta($cacheKey, $response);

            return $response;
        }

        if ($response->status() === 429 || $response->status() === 403) {
            // 403 from MET almost always means the User-Agent or coordinate
            // precision is wrong - worth surfacing loudly since it is a
            // configuration error, not a transient outage.
            $this->logger->error('weather-risk-map: MET Norway rejected the request', [
                'url' => $url,
                'status' => $response->status(),
                'hint' => $response->status() === 403
                    ? 'Check weather-risk-map.user_agent contains an application name and contact email.'
                    : 'Rate limited - increase cache TTLs or reduce the geo-cell count.',
            ]);

            return null;
        }

        if (! $response->successful()) {
            $this->logger->warning('weather-risk-map: MET Norway returned an error status', [
                'url' => $url,
                'status' => $response->status(),
            ]);

            return null;
        }

        $this->storeMeta($cacheKey, $response);

        return $response;
    }

    private function storeMeta(string $cacheKey, Response $response): void
    {
        $meta = [];

        $lastModified = $response->header('Last-Modified');
        if ($lastModified !== '') {
            $meta['last_modified'] = $lastModified;
        }

        $expires = $response->header('Expires');
        if ($expires !== '') {
            try {
                $meta['expires_at'] = CarbonImmutable::parse($expires)->toIso8601String();
            } catch (Throwable) {
                // Ignore an unparseable Expires header.
            }
        }

        if ($meta !== []) {
            $this->cache->putMeta($cacheKey, $meta, $this->forecastTtlSeconds * 8);
        }
    }

    /** @return array<string, mixed>|null */
    private function decode(Response $response, string $product): ?array
    {
        try {
            $json = $response->json();
        } catch (Throwable $e) {
            $this->logger->warning('weather-risk-map: malformed JSON from MET Norway', [
                'product' => $product,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        if (! is_array($json)) {
            $this->logger->warning('weather-risk-map: unexpected response shape from MET Norway', [
                'product' => $product,
                'type' => get_debug_type($json),
            ]);

            return null;
        }

        return $json;
    }

    // -------------------------------------------------------------- hydrate

    /**
     * Convert a Locationforecast payload into our DTO.
     *
     * Public so the parsing logic can be unit tested against recorded
     * fixtures without touching the network.
     *
     * @param  array<string, mixed>  $json
     */
    public function hydrateForecast(array $json, Coordinate $coordinate, ?CarbonImmutable $fetchedAt = null): ?Forecast
    {
        $series = $json['properties']['timeseries'] ?? null;
        if (! is_array($series)) {
            return null;
        }

        $points = [];

        foreach ($series as $entry) {
            if (! is_array($entry) || empty($entry['time'])) {
                continue;
            }

            try {
                $time = CarbonImmutable::parse($entry['time']);
            } catch (Throwable) {
                continue;
            }

            $instant = $entry['data']['instant']['details'] ?? [];
            $instant = is_array($instant) ? $instant : [];

            // Precipitation and the weather symbol live on the *interval*
            // blocks, not the instant. next_1_hours is preferred; MET drops it
            // beyond ~2 days, where next_6_hours is all that remains.
            $interval = $entry['data']['next_1_hours'] ?? $entry['data']['next_6_hours'] ?? [];
            $interval = is_array($interval) ? $interval : [];
            $intervalDetails = is_array($interval['details'] ?? null) ? $interval['details'] : [];

            $points[] = new ForecastPoint(
                time: $time,
                temperature: $this->float($instant['air_temperature'] ?? null),
                windSpeed: $this->float($instant['wind_speed'] ?? null),
                windGust: $this->float($instant['wind_speed_of_gust'] ?? null),
                precipitation: $this->float($intervalDetails['precipitation_amount'] ?? null),
                humidity: $this->float($instant['relative_humidity'] ?? null),
                symbolCode: is_string($interval['summary']['symbol_code'] ?? null)
                    ? $interval['summary']['symbol_code']
                    : null,
                probabilityOfThunder: $this->float($intervalDetails['probability_of_thunder'] ?? null),
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

    /**
     * Convert a MetAlerts GeoJSON FeatureCollection into warning DTOs.
     *
     * @param  array<string, mixed>  $json
     * @return list<WeatherWarning>
     */
    public function hydrateWarnings(array $json, CarbonImmutable $fetchedAt): array
    {
        $features = $json['features'] ?? [];
        if (! is_array($features)) {
            return [];
        }

        $warnings = [];
        $skipped = 0;

        foreach ($features as $index => $feature) {
            if (! is_array($feature)) {
                $skipped++;

                continue;
            }

            $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            if ($props === []) {
                $skipped++;

                continue;
            }

            $polygons = $this->parseGeometry($feature['geometry'] ?? null);

            $event = is_string($props['event'] ?? null) ? $props['event'] : '';
            $severityLabel = $this->severityLabel($props);

            $warnings[] = new WeatherWarning(
                id: $this->warningId($props, $index),
                hazard: $this->mapEvent($event),
                risk: $this->mapSeverity($props),
                severityLabel: $severityLabel,
                title: $this->warningTitle($props, $event),
                description: $this->string($props['description'] ?? null),
                instruction: $this->string($props['instruction'] ?? null),
                consequences: $this->string($props['consequences'] ?? null),
                area: $this->string($props['area'] ?? null),
                onset: $this->time($props['effective'] ?? $props['onset'] ?? null),
                expires: $this->time($props['expires'] ?? null),
                polygons: $polygons,
                source: 'MET Norway',
                sourceUrl: $this->string($props['web'] ?? null) ?? 'https://www.yr.no/en/browse/warnings',
                fetchedAt: $fetchedAt,
                eventName: $this->string($props['eventAwarenessName'] ?? null),
            );
        }

        if ($skipped > 0) {
            $this->logger->warning('weather-risk-map: skipped malformed MetAlerts features', [
                'skipped' => $skipped,
                'total' => count($features),
            ]);
        }

        return $warnings;
    }

    /**
     * @return list<Polygon>
     */
    private function parseGeometry(mixed $geometry): array
    {
        if (! is_array($geometry) || empty($geometry['type'])) {
            return [];
        }

        $coordinates = $geometry['coordinates'] ?? null;
        if (! is_array($coordinates)) {
            return [];
        }

        try {
            return match ($geometry['type']) {
                'Polygon' => array_values(array_filter([Polygon::fromGeoJson($coordinates)])),
                'MultiPolygon' => array_values(array_filter(array_map(
                    fn ($poly) => is_array($poly) ? Polygon::fromGeoJson($poly) : null,
                    $coordinates
                ))),
                default => [],
            };
        } catch (Throwable $e) {
            $this->logger->warning('weather-risk-map: failed to parse warning polygon', [
                'type' => $geometry['type'] ?? 'unknown',
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * MetAlerts event vocabulary, per the API documentation:
     * blowingSnow, forestFire, gale, ice, icing, lightning, polarLow, rain,
     * rainFlood, snow, stormSurge, wind.
     */
    private function mapEvent(string $event): HazardType
    {
        return match (strtolower($event)) {
            'wind' => HazardType::Wind,
            'gale', 'polarlow' => HazardType::Storm,
            'lightning' => HazardType::Lightning,
            'rain' => HazardType::Rain,
            'rainflood', 'flood', 'stormsurge' => HazardType::Flood,
            'snow', 'blowingsnow', 'avalanches' => HazardType::Snow,
            'ice', 'icing' => HazardType::Icing,
            default => HazardType::Other,
        };
    }

    /**
     * Map the published warning colour onto our risk scale.
     *
     * MET publishes both a colour (riskMatrixColor / awareness_level) and a
     * CAP severity. The colour is the authoritative public-facing level in
     * Norway, so it wins; CAP severity is only a fallback.
     *
     * awareness_level arrives as e.g. "2; yellow; Moderate".
     *
     * @param  array<string, mixed>  $props
     */
    private function mapSeverity(array $props): RiskLevel
    {
        $colour = strtolower(trim((string) ($props['riskMatrixColor'] ?? '')));

        if ($colour === '') {
            $awareness = strtolower((string) ($props['awareness_level'] ?? ''));
            foreach (['red', 'orange', 'yellow', 'green'] as $candidate) {
                if (str_contains($awareness, $candidate)) {
                    $colour = $candidate;
                    break;
                }
            }
        }

        $byColour = match ($colour) {
            'red' => RiskLevel::Severe,
            'orange' => RiskLevel::High,
            'yellow' => RiskLevel::Moderate,
            'green' => RiskLevel::Info,
            default => null,
        };

        if ($byColour !== null) {
            return $byColour;
        }

        return match (strtolower((string) ($props['severity'] ?? ''))) {
            'extreme' => RiskLevel::Severe,
            'severe' => RiskLevel::High,
            'moderate' => RiskLevel::Moderate,
            'minor' => RiskLevel::Info,
            default => RiskLevel::Moderate,
        };
    }

    /** @param array<string, mixed> $props */
    private function severityLabel(array $props): string
    {
        $colour = trim((string) ($props['riskMatrixColor'] ?? ''));
        if ($colour !== '') {
            return ucfirst(strtolower($colour));
        }

        $awareness = (string) ($props['awareness_level'] ?? '');
        if ($awareness !== '') {
            // "2; yellow; Moderate" -> "Yellow"
            $parts = array_map('trim', explode(';', $awareness));
            if (isset($parts[1]) && $parts[1] !== '') {
                return ucfirst(strtolower($parts[1]));
            }
        }

        $severity = trim((string) ($props['severity'] ?? ''));

        return $severity !== '' ? $severity : 'Unknown';
    }

    /** @param array<string, mixed> $props */
    private function warningTitle(array $props, string $event): string
    {
        foreach (['eventAwarenessName', 'event', 'headline'] as $field) {
            $value = $this->string($props[$field] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return $event !== '' ? ucfirst($event) : 'Weather warning';
    }

    /** @param array<string, mixed> $props */
    private function warningId(array $props, int $index): string
    {
        foreach (['id', 'identifier', 'cap_id'] as $field) {
            $value = $this->string($props[$field] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        // No stable identifier published - derive one so the front end has a
        // usable key. Includes the fields that make a warning distinct.
        return 'met-' . substr(sha1(implode('|', [
            (string) ($props['event'] ?? ''),
            (string) ($props['area'] ?? ''),
            (string) ($props['effective'] ?? ''),
            (string) ($props['expires'] ?? ''),
            (string) $index,
        ])), 0, 16);
    }

    private function float(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function string(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            $this->logger->debug('weather-risk-map: unparseable warning timestamp', ['value' => $value]);

            return null;
        }
    }
}
