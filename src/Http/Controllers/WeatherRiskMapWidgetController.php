<?php

namespace LibreNMS\Plugins\WeatherRiskMap\Http\Controllers;

use App\Facades\LibrenmsConfig;
use App\Http\Controllers\Widgets\WidgetController;
use App\Models\DeviceGroup;
use Illuminate\Http\Request;
use Illuminate\View\View;
use LibreNMS\Plugins\WeatherRiskMap\DTO\WidgetSettings;
use LibreNMS\Plugins\WeatherRiskMap\Enums\HazardType;

/**
 * The dashboard widget itself.
 *
 * Extends LibreNMS's own WidgetController so settings persistence, the
 * per-widget authorization check and the JSON envelope all behave exactly
 * like a built-in widget.
 *
 * This controller renders the SHELL only. It performs no weather I/O at all -
 * the view fetches data asynchronously from WeatherRiskDataController. That
 * separation is what stops a slow or dead weather API from delaying the whole
 * LibreNMS dashboard, which renders its widgets in parallel but waits on each
 * response.
 *
 * Registering the route under the `ajax/dash` prefix is what makes this
 * appear in the Add Widget list: DashboardController::listWidgets() discovers
 * widgets by scanning routes with that prefix, so no core file is touched.
 */
class WeatherRiskMapWidgetController extends WidgetController
{
    protected string $name = 'weather-risk-map';

    public function __construct()
    {
        $config = (array) config('weather-risk-map.defaults', []);

        $this->defaults = [
            'title' => null,
            'device_groups' => [],
            'refresh' => $config['refresh'] ?? 600,
            'risk_period' => $config['risk_period'] ?? 24,
            'min_severity' => $config['min_severity'] ?? 0,
            'weather_radius_km' => $config['weather_radius_km'] ?? 10,
            'clustering' => $config['clustering'] ?? true,
            'show_warnings' => $config['show_warnings'] ?? true,
            'map_mode' => $config['map_mode'] ?? 'auto',
            'hazards' => [],
            'thresholds' => [],
        ];
    }

    /**
     * Title shown in the Add Widget menu and the widget header.
     *
     * Overridden because the core implementation reads
     * `lang/en/widgets.{name}.title` from LibreNMS core, which this package
     * deliberately does not modify. Resolves from the package's own
     * translation namespace instead.
     */
    public function getTitle(): string
    {
        return __('weather-risk-map::widget.title');
    }

    public function getView(Request $request): View|string
    {
        $settings = WidgetSettings::fromArray($this->getSettings(), (array) config('weather-risk-map'));

        // Deliberately the core-style view name, not the namespaced one - see
        // WeatherRiskMapServiceProvider::boot() for why the name matters.
        return view('widgets.weather-risk-map', [
            'id' => $this->getSettings()['id'] ?? 0,
            'settings' => $settings,
            'settingsJson' => $settings->toArray(),
            'mapConfig' => $this->mapConfig(),
            'dimensions' => $request->input('dimensions'),
        ]);
    }

    /**
     * Reuse the installation's existing map configuration.
     *
     * LibreNMS already lets an administrator pick a tile engine and API key
     * under Settings -> External -> Geolocation, and the core world-map widget
     * honours it. Reading the same keys means this widget renders with the
     * same basemap as the rest of LibreNMS instead of imposing its own.
     *
     * The api_key here is a *tile* key that core already emits to the browser
     * in its own map widgets; no weather-provider credential is ever exposed.
     *
     * @return array<string, mixed>
     */
    private function mapConfig(): array
    {
        return [
            'engine' => LibrenmsConfig::get('geoloc.engine'),
            'api_key' => LibrenmsConfig::get('geoloc.api_key'),
            'tile_url' => LibrenmsConfig::get('leaflet.tile_url'),
            'layer' => LibrenmsConfig::get('geoloc.layer'),
        ];
    }

    /**
     * Settings panel.
     *
     * Overridden only to supply extra view data; the view NAME stays in core's
     * `widgets.settings.*` form so that core's formatResponse() correctly
     * reports the widget as being in settings mode.
     */
    public function getSettingsView(Request $request): View
    {
        $raw = $this->getSettings(true);
        $settings = WidgetSettings::fromArray($raw, (array) config('weather-risk-map'));

        // Resolve group ids to names so the multi-select can show the current
        // selection without an extra round trip.
        $selectedGroups = $settings->deviceGroups === []
            ? []
            : DeviceGroup::query()
                ->whereIn('id', $settings->deviceGroups)
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all();

        return view('widgets.settings.weather-risk-map', [
            'id' => $raw['id'] ?? 0,
            'refresh' => $settings->refresh,
            'settings' => $settings,
            'selectedGroups' => $selectedGroups,
            'hazards' => HazardType::toggleable(),
            'periods' => WidgetSettings::ALLOWED_PERIODS,
            'radii' => WidgetSettings::ALLOWED_RADII,
            'refreshOptions' => WidgetSettings::ALLOWED_REFRESH,
            'thresholdDefaults' => (array) config('weather-risk-map.thresholds', []),
        ]);
    }
}
