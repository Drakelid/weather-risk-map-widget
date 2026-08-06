<?php

namespace LibreNMS\Plugins\WeatherRiskMap\Console;

use App\Models\Device;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use LibreNMS\Plugins\WeatherRiskMap\DTO\Coordinate;
use LibreNMS\Plugins\WeatherRiskMap\Services\WeatherGeoService;
use LibreNMS\Plugins\WeatherRiskMap\Services\WeatherRiskMapFactory;
use Throwable;

/**
 * End-to-end health check.
 *
 * Written to be the first thing to run when the widget misbehaves: it
 * separates "LibreNMS has no coordinates" from "the provider is rejecting us"
 * from "the cache is broken", which are the three failure modes that look
 * identical in the browser.
 */
class DiagnoseCommand extends Command
{
    protected $signature = 'weather-risk-map:diagnose {--radius=10 : Geo-cell radius in km}';

    protected $description = 'Check device coordinates, weather provider connectivity and request volume';

    /**
     * Verify the widget will actually appear in the Add Widget menu.
     *
     * DashboardController::listWidgets() selects widgets with an exact string
     * comparison on the route prefix. A route can therefore be registered, be
     * visible in `route:list`, resolve correctly when called - and still never
     * appear in the UI, because its prefix string is "/ajax/dash" rather than
     * "ajax/dash". That failure is invisible without this check.
     *
     * listWidgets() itself cannot be called here: it instantiates every widget
     * controller and calls getTitle(), and core's ServerStatsController queries
     * Device::hasAccess(request()->user()), which is null on the CLI. So the
     * same filter is reproduced directly against the route table.
     */
    private function checkWidgetDiscoverable(): bool
    {
        $route = collect(Route::getRoutes())
            ->first(fn ($r) => str_ends_with($r->uri(), 'ajax/dash/weather-risk-map'));

        if ($route === null) {
            $this->error('  ! Route not registered at all.');
            $this->error('    Run: php artisan route:clear   (loadRoutesFrom is skipped when routes are cached)');

            return false;
        }

        $prefix = (string) $route->getPrefix();

        if ($prefix !== 'ajax/dash') {
            $this->error('  ! Route prefix is "' . $prefix . '", expected exactly "ajax/dash".');
            $this->error('    The widget will NOT appear in the Add Widget menu.');
            $this->error('    routes/web.php must nest prefix("ajax") then prefix("dash") - see the note there.');

            return false;
        }

        $this->line('  Route      : OK (' . $route->uri() . ')');
        $this->line('  Prefix     : OK ("ajax/dash" - matches listWidgets())');
        $this->line('  Add Widget : "' . __('weather-risk-map::widget.title') . '"');

        return true;
    }

    public function handle(WeatherRiskMapFactory $factory): int
    {
        $this->info('Weather Risk Map diagnostics');
        $this->line('');

        $failed = false;

        // ---------------------------------------------------- configuration
        $userAgent = (string) config('weather-risk-map.user_agent');
        $this->line('<comment>Configuration</comment>');
        $this->line('  Forecast provider : ' . config('weather-risk-map.providers.forecast'));
        $this->line('  Warning provider  : ' . (config('weather-risk-map.providers.warnings') ?: 'disabled'));
        $this->line('  User-Agent        : ' . $userAgent);

        // MET Norway returns 403 for a User-Agent without a contact email.
        if (str_contains($userAgent, 'set WEATHER_RISK_MAP_USER_AGENT')) {
            $this->warn('  ! User-Agent is still the placeholder. MET Norway requires a real contact');
            $this->warn('    email and will answer 403 without one. Set WEATHER_RISK_MAP_USER_AGENT.');
            $failed = true;
        }
        $this->line('');

        // ----------------------------------------------- widget registration
        $this->line('<comment>Widget registration</comment>');

        if (! $this->checkWidgetDiscoverable()) {
            $failed = true;
        }
        $this->line('');

        // ---------------------------------------------------------- devices
        $this->line('<comment>Device coordinates</comment>');

        $total = Device::query()->count();
        $withLocation = Device::query()->whereNotNull('location_id')->count();

        $coordinates = [];
        $invalid = 0;

        Device::query()
            ->whereNotNull('location_id')
            ->with('location:id,location,lat,lng')
            ->chunk(500, function ($devices) use (&$coordinates, &$invalid): void {
                foreach ($devices as $device) {
                    $coordinate = $device->location
                        ? Coordinate::fromRaw($device->location->lat, $device->location->lng)
                        : null;

                    if ($coordinate === null) {
                        $invalid++;

                        continue;
                    }

                    $coordinates[$device->location_id] = $coordinate;
                }
            });

        $this->line('  Devices total            : ' . $total);
        $this->line('  Devices with a location  : ' . $withLocation);
        $this->line('  Distinct valid locations : ' . count($coordinates));
        $this->line('  Skipped (no/bad coords)  : ' . $invalid);

        if ($coordinates === []) {
            $this->error('  ! No usable coordinates. Set lat/lng on locations, or configure');
            $this->error('    geoloc lookup, otherwise the map will always be empty.');
            $this->line('');

            return self::FAILURE;
        }
        $this->line('');

        // ------------------------------------------------------- geo cells
        $radius = (float) $this->option('radius');
        $geo = new WeatherGeoService($radius);
        $grouped = $geo->groupIntoCells($coordinates);
        $cells = count($grouped['cells']);
        $budget = (int) config('weather-risk-map.max_cells_per_refresh', 80);

        $this->line('<comment>Request volume</comment>');
        $this->line('  Radius                   : ' . $radius . ' km');
        $this->line('  Geo cells                : ' . $cells);
        $this->line('  Forecast requests/refresh: ' . min($cells, $budget) . ' (budget ' . $budget . ')');
        $this->line('  Warning requests/refresh : 1 (all warnings, matched locally)');

        if ($cells > $budget) {
            $this->warn('  ! Cell count exceeds the budget; raise weather_radius_km or max_cells_per_refresh.');
        }
        $this->line('');

        // -------------------------------------------------------- providers
        $this->line('<comment>Provider connectivity</comment>');

        $sample = reset($grouped['cells']);

        try {
            $forecast = $factory->forecastProvider()->getForecast($sample);

            if ($forecast === null) {
                $this->error('  ! Forecast fetch returned no data. See storage/logs/weather-risk-map.log');
                $failed = true;
            } else {
                $this->line('  Forecast  : OK (' . count($forecast->points) . ' points, '
                    . $forecast->ageInMinutes() . ' min old, sample ' . $sample->key() . ')');
            }
        } catch (Throwable $e) {
            $this->error('  ! Forecast fetch threw: ' . $e->getMessage());
            $failed = true;
        }

        $warningProvider = $factory->warningProvider();
        if ($warningProvider === null) {
            $this->line('  Warnings  : disabled by configuration');
        } else {
            try {
                $warnings = $warningProvider->getWarnings();
                $withGeometry = count(array_filter($warnings, fn ($w) => $w->polygons !== []));

                $this->line('  Warnings  : OK (' . count($warnings) . ' active, '
                    . $withGeometry . ' with polygons)');

                foreach (array_slice($warnings, 0, 5) as $warning) {
                    $this->line('              - [' . $warning->severityLabel . '] '
                        . ($warning->eventName ?? $warning->title)
                        . ' | ' . ($warning->area ?? 'no area')
                        . ' | ' . ($warning->formattedPeriod('d.m H:i') ?? 'no period'));
                }
            } catch (Throwable $e) {
                $this->error('  ! Warning fetch threw: ' . $e->getMessage());
                $failed = true;
            }
        }

        $this->line('');
        $this->line($failed ? '<error>Diagnostics finished with problems.</error>' : '<info>All checks passed.</info>');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
