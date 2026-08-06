<?php

namespace LibreNMS\Plugins\WeatherRiskMap\Console;

use App\Models\Device;
use Illuminate\Console\Command;
use LibreNMS\Plugins\WeatherRiskMap\DTO\Coordinate;
use LibreNMS\Plugins\WeatherRiskMap\Services\WeatherGeoService;
use LibreNMS\Plugins\WeatherRiskMap\Services\WeatherRiskMapFactory;
use Throwable;

/**
 * Pre-populate the weather cache.
 *
 * Optional, but worthwhile on a wall-mounted NOC dashboard: it moves the
 * occasional cache-miss latency off the operator's refresh and onto a
 * scheduled job, and it keeps the stale-fallback copy recent so an outage
 * that begins overnight still has usable data behind it.
 *
 * Suggested schedule (in LibreNMS's own scheduler or cron), comfortably
 * inside the provider rate limit:
 *
 *     php artisan weather-risk-map:warm     # every 10 minutes
 *
 * Deliberately NOT registered in the scheduler by this package: an
 * installation that never opens the widget should generate no API traffic at
 * all, so warming is opt-in.
 */
class WarmCacheCommand extends Command
{
    protected $signature = 'weather-risk-map:warm
                            {--radius=10 : Geo-cell radius in km, match your widget setting}
                            {--quiet-success : Print nothing unless something fails}';

    protected $description = 'Pre-fetch forecasts and warnings into the weather cache';

    public function handle(WeatherRiskMapFactory $factory): int
    {
        $radius = (float) $this->option('radius');
        $quiet = (bool) $this->option('quiet-success');

        $coordinates = [];

        Device::query()
            ->whereNotNull('location_id')
            ->with('location:id,location,lat,lng')
            ->chunk(500, function ($devices) use (&$coordinates): void {
                foreach ($devices as $device) {
                    if (isset($coordinates[$device->location_id])) {
                        continue;
                    }

                    $coordinate = $device->location
                        ? Coordinate::fromRaw($device->location->lat, $device->location->lng)
                        : null;

                    if ($coordinate !== null) {
                        $coordinates[$device->location_id] = $coordinate;
                    }
                }
            });

        if ($coordinates === []) {
            $this->warn('No devices with valid coordinates; nothing to warm.');

            return self::SUCCESS;
        }

        $geo = new WeatherGeoService($radius);
        $cells = $geo->groupIntoCells($coordinates)['cells'];

        $budget = (int) config('weather-risk-map.max_cells_per_refresh', 80);
        if (count($cells) > $budget) {
            $cells = array_slice($cells, 0, $budget, preserve_keys: true);
            $this->warn('Cell count exceeds the budget; warming the first ' . $budget . '.');
        }

        $warnings = 0;
        $warningProvider = $factory->warningProvider();

        if ($warningProvider !== null) {
            try {
                $warnings = count($warningProvider->getWarnings());
            } catch (Throwable $e) {
                $this->error('Warning fetch failed: ' . $e->getMessage());

                return self::FAILURE;
            }
        }

        try {
            $forecasts = $factory->forecastProvider()->getForecasts($cells);
        } catch (Throwable $e) {
            $this->error('Forecast fetch failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $missing = count($cells) - count($forecasts);

        if ($missing > 0) {
            $this->warn(sprintf(
                'Warmed %d/%d cells and %d warnings; %d cells unavailable (see the log).',
                count($forecasts), count($cells), $warnings, $missing
            ));

            return self::FAILURE;
        }

        if (! $quiet) {
            $this->info(sprintf(
                'Warmed %d forecast cells (%d locations) and %d active warnings.',
                count($forecasts), count($coordinates), $warnings
            ));
        }

        return self::SUCCESS;
    }
}
