<?php

namespace LibreNMS\Plugins\WeatherRiskMap\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use LibreNMS\Plugins\WeatherRiskMap\Contracts\WeatherProviderInterface;
use LibreNMS\Plugins\WeatherRiskMap\DTO\Coordinate;
use LibreNMS\Plugins\WeatherRiskMap\DTO\Forecast;
use LibreNMS\Plugins\WeatherRiskMap\DTO\WeatherWarning;
use LibreNMS\Plugins\WeatherRiskMap\Enums\HazardType;
use LibreNMS\Plugins\WeatherRiskMap\Enums\RiskLevel;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Assembles the complete widget payload.
 *
 * Order of operations, and why:
 *
 *   1. Devices are loaded first and are ALWAYS returned, even if every
 *      weather call fails. LibreNMS data must never be hostage to an
 *      external API.
 *   2. Devices collapse to locations, locations collapse to grid cells.
 *   3. ONE warnings request covers the whole country; polygons are matched
 *      to cells locally.
 *   4. Forecasts are fetched per cell, capped per refresh, cache-first.
 *   5. Risk is computed per device from its cell's forecast plus its cell's
 *      matched warnings, then adjusted for device-group sensitivity.
 *
 * Every weather step is individually guarded: a failure downgrades the
 * payload and sets a degraded flag, it never throws to the controller.
 */
class WeatherRiskMapBuilder
{
    public function __construct(
        private readonly DeviceLocationService $devices,
        private readonly WeatherGeoService $geo,
        private readonly WeatherRiskService $risk,
        private readonly SensitivityProfileService $sensitivity,
        private readonly WeatherProviderInterface $forecastProvider,
        private readonly ?WeatherProviderInterface $warningProvider,
        private readonly LoggerInterface $logger,
        private readonly int $maxCellsPerRefresh = 80,
    ) {}

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function build(User $user, array $settings): array
    {
        $now = CarbonImmutable::now();
        $windowHours = (int) ($settings['risk_period'] ?? 24);
        $enabledHazards = $this->enabledHazards($settings);
        $deviceGroupIds = array_map('intval', (array) ($settings['device_groups'] ?? []));

        $devices = $this->devices->devicesWithCoordinates($user, $deviceGroupIds);

        if ($devices->isEmpty()) {
            return $this->emptyPayload($now, $windowHours, 'No devices with valid GPS coordinates match the current filter.');
        }

        $locations = $this->devices->distinctLocations($devices);
        $grouped = $this->geo->groupIntoCells($locations);
        $cells = $grouped['cells'];

        // ------------------------------------------------ warnings (1 request)
        $warnings = [];
        $warningsDegraded = false;

        if (($settings['show_warnings'] ?? true) && $this->warningProvider !== null) {
            try {
                $warnings = $this->warningProvider->getWarnings();
            } catch (Throwable $e) {
                $warningsDegraded = true;
                $this->logger->error('weather-risk-map: warning provider threw', [
                    'provider' => $this->warningProvider->key(),
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $cellWarnings = $warnings === [] ? [] : $this->geo->matchWarningsToCells($cells, $warnings);

        // ------------------------------------------------ forecasts (per cell)
        [$forecasts, $forecastDegraded, $forecastMeta] = $this->fetchForecasts($cells);

        // ------------------------------------------------------ per-device risk
        $locationCell = [];
        foreach ($grouped['members'] as $cellId => $locationIds) {
            foreach ($locationIds as $locationId) {
                $locationCell[$locationId] = $cellId;
            }
        }

        $rows = [];
        $counts = array_fill_keys(array_map(fn (RiskLevel $l) => $l->value, RiskLevel::cases()), 0);
        $highest = null;

        foreach ($devices as $device) {
            $cellId = $locationCell[$device['location_id']] ?? null;
            $forecast = $cellId !== null ? ($forecasts[$cellId] ?? null) : null;
            $deviceWarnings = $cellId !== null ? ($cellWarnings[$cellId] ?? []) : [];

            $assessments = $this->risk->assess(
                $forecast,
                $deviceWarnings,
                $now,
                $windowHours,
                $enabledHazards,
                $device['group_ids'],
            );

            $overall = $this->risk->overallRisk($assessments);
            $counts[$overall->value]++;

            $current = $forecast?->nearestTo($now);

            $row = [
                'device_id' => $device['device_id'],
                'hostname' => $device['hostname'],
                'display_name' => $device['display_name'],
                'location' => $device['location'],
                'lat' => $device['coordinate']->lat,
                'lng' => $device['coordinate']->lng,
                'up' => $device['status'],
                'disabled' => $device['disabled'],
                'risk' => $overall->value,
                'risk_label' => $overall->label(),
                'risk_color' => $overall->hex(),
                'risk_emoji' => $overall->emoji(),
                'hazards' => array_values(array_map(fn ($a) => $a->toArray(), $assessments)),
                'sensitivity' => $this->sensitivity->labelsFor($device['group_ids']),
                'groups' => $device['group_names'],
                'has_forecast' => $forecast !== null,
                'current' => $current === null ? null : [
                    'temperature' => $current->temperature,
                    'wind' => $current->windSpeed,
                    'gust' => $current->windGust,
                    'precipitation' => $current->precipitation,
                    'symbol' => $current->symbolCode,
                ],
                'warnings' => array_values(array_map(
                    fn (WeatherWarning $w) => ['id' => $w->id, 'title' => $w->eventName ?? $w->title, 'risk' => $w->risk->value],
                    array_filter($deviceWarnings, fn (WeatherWarning $w) => $w->overlapsWindow($now, $now->addHours(max(1, $windowHours))))
                )),
            ];

            $rows[] = $row;

            if ($overall->atLeast(RiskLevel::Moderate)) {
                $top = $assessments === [] ? null : reset($assessments);
                if ($top !== null && ($highest === null || $overall->value > $highest['risk'])) {
                    $highest = [
                        'risk' => $overall->value,
                        'risk_label' => $overall->label(),
                        'hazard' => $top->hazard->label(),
                        'detail' => $top->detail,
                        'device' => $device['display_name'],
                        'device_id' => $device['device_id'],
                        'location' => $device['location'],
                        'time' => $top->peakTime?->toIso8601String(),
                    ];
                }
            }
        }

        $activeWarnings = array_values(array_filter(
            $warnings,
            fn (WeatherWarning $w) => $w->overlapsWindow($now, $now->addHours(max(1, $windowHours)))
        ));

        return [
            'generated_at' => $now->toIso8601String(),
            'window_hours' => $windowHours,
            'devices' => $rows,
            'counts' => [
                'extreme' => $counts[RiskLevel::Extreme->value],
                'severe' => $counts[RiskLevel::Severe->value],
                'high' => $counts[RiskLevel::High->value],
                'moderate' => $counts[RiskLevel::Moderate->value],
                'info' => $counts[RiskLevel::Info->value],
                'normal' => $counts[RiskLevel::Normal->value],
                'total' => count($rows),
            ],
            'warnings' => array_map(fn (WeatherWarning $w) => $this->warningForDisplay($w, (bool) ($settings['show_warnings'] ?? true)), $activeWarnings),
            'highest' => $highest,
            'bounds' => $this->geo->bounds(array_map(fn ($d) => $d['coordinate'], $devices->all())),
            'meta' => [
                'forecast_provider' => $this->forecastProvider->name(),
                'warning_provider' => $this->warningProvider?->name(),
                'attribution' => $this->attribution(),
                'cells' => count($cells),
                'locations' => count($locations),
                'radius_km' => $this->geo->radiusKm(),
                'degraded' => $forecastDegraded || $warningsDegraded,
                'forecast_degraded' => $forecastDegraded,
                'warnings_degraded' => $warningsDegraded,
                'stale' => $forecastMeta['stale'] ?? false,
                'oldest_data_minutes' => $forecastMeta['oldest_minutes'] ?? null,
                'last_success' => $forecastMeta['last_success'] ?? null,
                'degraded_reason' => $this->degradedReason($forecastDegraded, $warningsDegraded),
                'cells_fetched' => $forecastMeta['fetched'] ?? 0,
                'cells_skipped' => $forecastMeta['skipped'] ?? 0,
            ],
        ];
    }

    /**
     * Fetch forecasts cell by cell, respecting the per-refresh cap.
     *
     * The cap is a hard ceiling on outbound requests per dashboard refresh.
     * Cells beyond it are not dropped - they fall back to whatever cached
     * data exists and are reported as skipped, so an unusually large install
     * degrades in resolution rather than hammering the provider.
     *
     * @param  array<string, Coordinate>  $cells
     * @return array{0: array<string, Forecast>, 1: bool, 2: array<string, mixed>}
     */
    private function fetchForecasts(array $cells): array
    {
        if ($cells === []) {
            return [[], false, []];
        }

        $budgeted = $cells;
        $skipped = 0;

        if (count($cells) > $this->maxCellsPerRefresh) {
            $budgeted = array_slice($cells, 0, $this->maxCellsPerRefresh, preserve_keys: true);
            $skipped = count($cells) - count($budgeted);

            $this->logger->warning('weather-risk-map: geo-cell count exceeds the per-refresh budget', [
                'cells' => count($cells),
                'budget' => $this->maxCellsPerRefresh,
                'skipped' => $skipped,
                'hint' => 'Increase weather_radius_km to merge sites, or raise max_cells_per_refresh.',
            ]);
        }

        try {
            $forecasts = $this->forecastProvider->getForecasts($budgeted);
        } catch (Throwable $e) {
            $this->logger->error('weather-risk-map: forecast provider threw', [
                'provider' => $this->forecastProvider->key(),
                'message' => $e->getMessage(),
            ]);

            return [[], true, ['skipped' => $skipped]];
        }

        $degraded = count($forecasts) < count($budgeted);

        $oldest = null;
        $newest = null;
        foreach ($forecasts as $forecast) {
            $age = $forecast->ageInMinutes();
            $oldest = $oldest === null ? $age : max($oldest, $age);
            $newest = $newest === null ? $forecast->fetchedAt : $forecast->fetchedAt->max($newest);
        }

        return [
            $forecasts,
            $degraded,
            [
                'fetched' => count($forecasts),
                'skipped' => $skipped,
                'oldest_minutes' => $oldest,
                'last_success' => $newest?->toIso8601String(),
                // Anything older than roughly two refresh cycles is worth
                // flagging to the operator as potentially out of date.
                'stale' => $oldest !== null && $oldest > 30,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function warningForDisplay(WeatherWarning $warning, bool $includeGeometry): array
    {
        $data = $warning->toArray();

        if ($includeGeometry) {
            // Simplified rings only: full national geometry is megabytes and
            // Leaflet does not need that precision to draw a warning area.
            $data['polygons'] = array_values(array_filter(array_map(
                fn ($polygon) => $polygon->simplifiedRing(),
                $warning->polygons
            )));
        } else {
            $data['polygons'] = [];
        }

        return $data;
    }

    /** @return list<HazardType> */
    private function enabledHazards(array $settings): array
    {
        $enabled = [];

        foreach (HazardType::toggleable() as $hazard) {
            if (($settings['hazards'][$hazard->value] ?? true)) {
                $enabled[] = $hazard;
            }
        }

        // Warnings that do not map to a known hazard still matter.
        $enabled[] = HazardType::Other;

        return $enabled;
    }

    private function attribution(): string
    {
        $parts = [$this->forecastProvider->attribution()];

        if ($this->warningProvider !== null && $this->warningProvider->key() !== $this->forecastProvider->key()) {
            $parts[] = $this->warningProvider->attribution();
        }

        return implode(' | ', array_unique($parts));
    }

    /**
     * Which half of the weather data is missing.
     *
     * Returned as a token rather than a sentence: the widget composes the
     * message so timestamps render in the viewer's timezone and the text can
     * be translated.
     */
    private function degradedReason(bool $forecastDegraded, bool $warningsDegraded): ?string
    {
        return match (true) {
            $forecastDegraded && $warningsDegraded => 'both',
            $forecastDegraded => 'forecast',
            $warningsDegraded => 'warnings',
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function emptyPayload(CarbonImmutable $now, int $windowHours, string $message): array
    {
        return [
            'generated_at' => $now->toIso8601String(),
            'window_hours' => $windowHours,
            'devices' => [],
            'counts' => ['extreme' => 0, 'severe' => 0, 'high' => 0, 'moderate' => 0, 'info' => 0, 'normal' => 0, 'total' => 0],
            'warnings' => [],
            'highest' => null,
            'bounds' => null,
            'meta' => [
                'forecast_provider' => $this->forecastProvider->name(),
                'warning_provider' => $this->warningProvider?->name(),
                'attribution' => $this->attribution(),
                'cells' => 0,
                'locations' => 0,
                'radius_km' => $this->geo->radiusKm(),
                'degraded' => false,
                'forecast_degraded' => false,
                'warnings_degraded' => false,
                'stale' => false,
                'oldest_data_minutes' => null,
                'last_success' => null,
                'cells_fetched' => 0,
                'cells_skipped' => 0,
                'degraded_reason' => null,
                'empty_reason' => $message,
            ],
        ];
    }
}
