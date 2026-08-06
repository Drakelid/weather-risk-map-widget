<?php

namespace LibreNMS\Plugins\WeatherRiskMap\Services;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Client\Factory as HttpFactory;
use LibreNMS\Plugins\WeatherRiskMap\Contracts\WeatherProviderInterface;
use LibreNMS\Plugins\WeatherRiskMap\DTO\WidgetSettings;
use LibreNMS\Plugins\WeatherRiskMap\Providers\MetNorwayWeatherProvider;
use LibreNMS\Plugins\WeatherRiskMap\Providers\OpenMeteoWeatherProvider;
use Psr\Log\LoggerInterface;

/**
 * Builds a WeatherRiskMapBuilder configured for one widget.
 *
 * A plain container binding is not enough here because two settings that vary
 * per widget - the geographic radius and the risk thresholds - are constructor
 * state of WeatherGeoService and WeatherRiskService. Two widgets on the same
 * dashboard can legitimately use a 5 km radius and a 50 km radius.
 *
 * Providers, by contrast, are installation-wide and are memoised so several
 * widgets on one page share their caches and HTTP client.
 *
 * Adding a provider means implementing WeatherProviderInterface and adding one
 * case to makeProvider(); nothing else in the package changes.
 */
class WeatherRiskMapFactory
{
    /** @var array<string, WeatherProviderInterface> */
    private array $providers = [];

    public function __construct(
        private readonly HttpFactory $http,
        private readonly CacheFactory $cache,
        private readonly LoggerInterface $logger,
        private readonly DeviceLocationService $devices,
    ) {}

    public function forSettings(WidgetSettings $settings): WeatherRiskMapBuilder
    {
        $config = (array) config('weather-risk-map');

        $sensitivity = new SensitivityProfileService(
            profiles: (array) ($config['sensitivity']['profiles'] ?? []),
            enabled: (bool) ($config['sensitivity']['enabled'] ?? true),
        );

        return new WeatherRiskMapBuilder(
            devices: $this->devices,
            geo: new WeatherGeoService((float) $settings->weatherRadiusKm),
            risk: new WeatherRiskService($settings->thresholds, $sensitivity),
            sensitivity: $sensitivity,
            forecastProvider: $this->forecastProvider(),
            warningProvider: $this->warningProvider(),
            logger: $this->logger,
            maxCellsPerRefresh: (int) ($config['max_cells_per_refresh'] ?? 80),
        );
    }

    public function forecastProvider(): WeatherProviderInterface
    {
        return $this->provider((string) config('weather-risk-map.providers.forecast', 'met-norway'));
    }

    public function warningProvider(): ?WeatherProviderInterface
    {
        $key = config('weather-risk-map.providers.warnings', 'met-norway');

        if ($key === null || $key === '' || $key === false) {
            return null;
        }

        $provider = $this->provider((string) $key);

        // Selecting a provider that publishes no warnings is a configuration
        // mistake; say so once rather than silently showing no warnings.
        if (! $provider->supportsWarnings()) {
            $this->logger->warning('weather-risk-map: configured warning provider publishes no warnings', [
                'provider' => $provider->key(),
            ]);

            return null;
        }

        return $provider;
    }

    public function provider(string $key): WeatherProviderInterface
    {
        return $this->providers[$key] ??= $this->makeProvider($key);
    }

    private function makeProvider(string $key): WeatherProviderInterface
    {
        $config = (array) config('weather-risk-map');
        $userAgent = (string) ($config['user_agent'] ?? 'LibreNMS-WeatherRiskMap/1.0');
        $timeout = (int) ($config['http']['timeout'] ?? 8);

        $cache = new WeatherCacheService(
            cache: $this->cache->store(),
            logger: $this->logger,
            staleTtlMinutes: (int) ($config['cache']['stale_ttl_minutes'] ?? 360),
            failureBackoffSeconds: (int) ($config['cache']['failure_backoff_seconds'] ?? 60),
        );

        return match ($key) {
            'open-meteo' => new OpenMeteoWeatherProvider(
                http: $this->http,
                cache: $cache,
                logger: $this->logger,
                userAgent: $userAgent,
                forecastTtlSeconds: (int) ($config['cache']['forecast_ttl'] ?? 900),
                timeoutSeconds: $timeout,
            ),
            default => new MetNorwayWeatherProvider(
                http: $this->http,
                cache: $cache,
                logger: $this->logger,
                userAgent: $userAgent,
                forecastTtlSeconds: (int) ($config['cache']['forecast_ttl'] ?? 900),
                warningsTtlSeconds: (int) ($config['cache']['warnings_ttl'] ?? 300),
                timeoutSeconds: $timeout,
                language: (string) ($config['language'] ?? 'en'),
            ),
        };
    }
}
