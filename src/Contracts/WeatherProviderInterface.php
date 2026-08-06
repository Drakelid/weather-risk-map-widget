<?php

namespace LibreNMS\Plugins\WeatherRiskMap\Contracts;

use LibreNMS\Plugins\WeatherRiskMap\DTO\Coordinate;
use LibreNMS\Plugins\WeatherRiskMap\DTO\Forecast;
use LibreNMS\Plugins\WeatherRiskMap\DTO\WeatherWarning;

/**
 * A source of forecasts and official warnings.
 *
 * Implementations must be side-effect free apart from HTTP and caching, and
 * must never throw for an upstream failure: return null / an empty list and
 * log instead, so a provider outage degrades the widget rather than breaking
 * the LibreNMS dashboard.
 */
interface WeatherProviderInterface
{
    /** Stable identifier used in cache keys and settings. */
    public function key(): string;

    /** Human-readable name shown in the UI. */
    public function name(): string;

    /** Attribution string the provider's licence requires us to display. */
    public function attribution(): string;

    /**
     * Forecast for one point. Returns null when the forecast cannot be
     * obtained and no usable cached copy exists.
     */
    public function getForecast(Coordinate $coordinate): ?Forecast;

    /**
     * Fetch forecasts for many points.
     *
     * Exists so a provider can batch (Open-Meteo accepts comma-separated
     * coordinate lists) instead of being driven in a loop by the caller.
     *
     * @param  array<string, Coordinate>  $coordinates  keyed by cell id
     * @return array<string, Forecast>  keyed by the same cell ids; missing
     *                                  keys mean "unavailable for that cell"
     */
    public function getForecasts(array $coordinates): array;

    /**
     * Every currently active official warning this provider publishes.
     *
     * Intentionally not parameterised by location: the whole point is to make
     * one request and match locally. Returns an empty list on failure.
     *
     * @return list<WeatherWarning>
     */
    public function getWarnings(): array;

    /** Whether this provider publishes official warnings at all. */
    public function supportsWarnings(): bool;

    /**
     * Whether the provider has meaningful coverage at a point. Lets the
     * orchestrator skip a regional provider for out-of-area devices.
     */
    public function covers(Coordinate $coordinate): bool;
}
