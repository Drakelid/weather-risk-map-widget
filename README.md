# Weather Risk Map — LibreNMS dashboard widget

A NOC-oriented dashboard widget that correlates the GPS coordinates already stored on LibreNMS devices with forecasts and official severe-weather warnings, and reports an **infrastructure** risk level rather than a weather report.

Built and verified against LibreNMS `26.7.0-68-g1dfe1994d`, PHP `^8.2`, Laravel `^12.10`.

---

## 1. Architecture summary

```
 Browser (dashboard)
   │  POST ajax/dash/weather-risk-map        → renders the shell only, no weather I/O
   │  POST plugin/weather-risk-map/data      → asynchronous payload
   ▼
 WeatherRiskDataController
   └── WeatherRiskMapFactory  (per-widget wiring: radius + thresholds vary per widget)
         └── WeatherRiskMapBuilder                          ← orchestration
               ├── DeviceLocationService   devices + coordinates from LibreNMS
               ├── WeatherGeoService       grid cells, point-in-polygon, bounds
               ├── WeatherRiskService      thresholds, official warnings, scoring
               ├── SensitivityProfileService  per-device-group hazard offsets
               └── WeatherProviderInterface
                     ├── MetNorwayWeatherProvider   forecast + official warnings
                     └── OpenMeteoWeatherProvider   forecast (batched, worldwide)
                           └── WeatherCacheService  two-tier cache + stale fallback
```

**Why the widget renders before its data.** The dashboard requests each widget over AJAX and swaps in the returned HTML. If this controller waited on the weather APIs, a slow provider would visibly stall the widget. The widget controller therefore performs no weather I/O at all; the shell paints immediately and fetches its payload separately.

**Why device count does not drive API traffic.** Three collapses happen in sequence:

1. Devices already share a LibreNMS `locations` row — a POP with 40 switches is *one* coordinate before anything else happens.
2. Locations snap to a grid whose cell size is the configured weather radius. Sites a forecast cannot distinguish share one lookup.
3. Official warnings are fetched **once for the entire country** and matched locally with point-in-polygon.

A 2,000-device Norwegian ISP across ~150 sites typically resolves to 30–60 forecast requests per 15 minutes plus one warnings request per 5 minutes — and both are cached, so a NOC wall with eight browsers open generates the same traffic as one.

A hard `max_cells_per_refresh` ceiling bounds the worst case; cells beyond it reuse cached data rather than being dropped.

**Why the cache has two tiers.** Every entry is written both as `fresh:` (short TTL) and `stale:` (long TTL). A single-tier cache loses the data exactly when the provider goes down and you need it most. On failure the widget serves the stale copy and labels it with its age. A failure marker additionally stops a dead provider being retried by every dashboard refresh from every logged-in operator.

---

## 2. Weather APIs selected, and why

### MET Norway — primary (`met-norway`)

| Product | Endpoint | Used for |
| --- | --- | --- |
| Locationforecast 2.0 compact | `api.met.no/weatherapi/locationforecast/2.0/compact` | Point forecast per geo cell |
| MetAlerts 2.0 | `api.met.no/weatherapi/metalerts/2.0/current.json` | Every active Norwegian warning, as GeoJSON polygons, in **one request** |

Chosen because it is the authoritative source for Norway, it is the *only* one of the candidates that publishes official warning polygons with severity, and MetAlerts' single-request national coverage is what makes local polygon matching possible.

Terms-of-service compliance is implemented, not just intended:

- identifying `User-Agent` with a contact email (MET returns **403** without one)
- coordinates truncated to **4 decimals** (MET returns **403** with more)
- the `Expires` header is honoured — `heldByExpires()` refuses to re-request inside the window even if the local TTL is shorter
- `If-Modified-Since` sent with the exact previous `Last-Modified`; a `304` refreshes the TTL without re-downloading
- 20 req/s application-wide — geo-cell grouping keeps us orders of magnitude below it
- CC BY 4.0 attribution rendered in the widget footer

### Open-Meteo — secondary (`open-meteo`)

Kept behind the same interface for two reasons: its forecast endpoint accepts **comma-separated coordinate lists**, so all cells arrive in one request; and it gives worldwide coverage for devices outside MET Norway's warning area. It publishes no official warnings, so `supportsWarnings()` is `false` and warnings continue to come from MET Norway.

### Lightning — an honest limitation

No free, reliable public API provides real-time lightning strike data. Lightning is therefore modelled as **risk** from thunder indications in the forecast (`probability_of_thunder` where available, otherwise the `…andthunder` symbol suffix). It is flagged `inferred` in the payload and rendered as *"(risk, not detected strikes)"* in the UI. Nothing is presented as a detected strike.

---

## 3. Files created

Everything lives in this package. **No LibreNMS core file is created or modified.**

| File | Purpose |
| --- | --- |
| `composer.json` | Package definition, PSR-4, Laravel provider discovery |
| `phpunit.xml` | Test configuration |
| `README.md` | This document |
| `config/weather-risk-map.php` | Installation-wide defaults: providers, User-Agent, TTLs, thresholds, sensitivity |
| `routes/web.php` | Widget route (`ajax/dash` prefix) + data endpoint |
| `lang/en/widget.php` | All user-facing strings |
| `src/WeatherRiskMapServiceProvider.php` | Registration, log channel, bindings |
| `src/Contracts/WeatherProviderInterface.php` | Provider contract |
| `src/Enums/RiskLevel.php` | 0–5 risk scale, colours, labels |
| `src/Enums/HazardType.php` | Hazard classes and ISP impact notes |
| `src/DTO/Coordinate.php` | Validated WGS84 coordinate — the single place coordinate validity is decided |
| `src/DTO/ForecastPoint.php` | One forecast hour |
| `src/DTO/Forecast.php` | Time series + windowing |
| `src/DTO/WeatherWarning.php` | Official warning + geometry |
| `src/DTO/HazardAssessment.php` | Peak of one hazard, official/inferred flags |
| `src/DTO/WidgetSettings.php` | Validation and normalisation of untrusted widget settings |
| `src/Support/Polygon.php` | Ray-casting point-in-polygon with bbox fast path, holes, simplification |
| `src/Support/GeoMath.php` | Haversine distance, degree/km conversion |
| `src/Providers/MetNorwayWeatherProvider.php` | MET Norway forecast + MetAlerts |
| `src/Providers/OpenMeteoWeatherProvider.php` | Open-Meteo batched forecast |
| `src/Services/WeatherCacheService.php` | Two-tier cache, stale fallback, failure backoff |
| `src/Services/WeatherGeoService.php` | Cell grouping, warning matching, bounds |
| `src/Services/WeatherRiskService.php` | **The documented risk algorithm** |
| `src/Services/SensitivityProfileService.php` | Per-device-group hazard offsets |
| `src/Services/DeviceLocationService.php` | Device + coordinate retrieval (no N+1) |
| `src/Services/WeatherRiskMapBuilder.php` | Orchestration, degradation handling |
| `src/Services/WeatherRiskMapFactory.php` | Per-widget service wiring |
| `src/Http/Controllers/WeatherRiskMapWidgetController.php` | The widget (extends core `WidgetController`) |
| `src/Http/Controllers/WeatherRiskDataController.php` | Async data endpoint |
| `src/Console/DiagnoseCommand.php` | End-to-end health check |
| `src/Console/WarmCacheCommand.php` | Optional cache pre-warming |
| `resources/views/widgets/weather-risk-map.blade.php` | Map, summary header, risk table, dark-mode CSS |
| `resources/views/widgets/settings/weather-risk-map.blade.php` | Settings panel |
| `tests/Support/ArrayCacheStub.php` | In-memory cache for unit tests |
| `tests/Unit/*.php` | 6 unit test classes |

## 4. Files modified

**None.**

This is the part of the design worth stating plainly. LibreNMS builds its widget catalogue in `DashboardController::listWidgets()` by scanning the route table for routes whose prefix is exactly `ajax/dash` — the `widgets` database table was dropped in 2022. Registering a route with that prefix from this package's own service provider is therefore sufficient to make "Weather Risk Map" appear in the **Add Widget** menu. No core controller, view, route file, language file or migration is touched, so a LibreNMS upgrade cannot overwrite the feature.

Two details make the widget behave like a built-in one rather than a bolted-on approximation:

- The package's view directory is registered with `View::addLocation()` in addition to a namespace, so the views resolve as `widgets.weather-risk-map` and `widgets.settings.weather-risk-map`. Core decides whether a widget is currently in settings mode with `Str::startsWith($view->getName(), 'widgets.settings.')`; a namespaced view name fails that test and the settings panel would silently drop out of edit mode on the next auto-refresh. Core's own view paths are registered first, so this cannot shadow a LibreNMS view.
- The widget's outer element carries `data-reload="false"`, which makes core's refresh timer dispatch a `refresh` event instead of re-fetching and re-injecting the HTML. The Leaflet map is therefore built once and updated in place, rather than being destroyed and recreated every cycle.

## 5. Database changes

**None.** No migrations, no new tables, no new columns. Widget settings use LibreNMS's existing per-user `users_widgets.settings` JSON column; coordinates are read from the existing `locations` table. This package stores no device coordinates of its own.

---

## 6. Installation

Leaflet and Leaflet.markercluster are **already bundled with LibreNMS** (`html/js/leaflet.js`, `html/js/leaflet.markercluster.js`) and the widget reuses core's `init_map()` / `get_map()` / `destroy_map()` helpers. There are no JavaScript dependencies to install and no asset build step.

From the LibreNMS directory, as the `librenms` user:

```sh
# 1. Register this directory as a Composer path repository
composer config repositories.weather-risk-map \
  '{"type":"path","url":"../weather-risk-map-widget","options":{"symlink":true}}'

# 2. Install the package
./lnms plugin:add librenms/weather-risk-map-widget @dev

# 3. Publish the config so it survives package updates
php artisan vendor:publish --tag=weather-risk-map-config

# 4. REQUIRED: set a real contact email (MET Norway returns 403 without one)
#    Add to .env:
#    WEATHER_RISK_MAP_USER_AGENT="YourISP-LibreNMS/1.0 noc@yourisp.no"

# 5. Clear caches
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear

# 6. Verify
php artisan weather-risk-map:diagnose
```

Then add the widget: **Overview → dashboard → edit (pencil) → Add Widget → Weather Risk Map**.

If your installation caches config or routes for performance, re-run `php artisan config:cache` / `route:cache` after step 5.

No service restart is required for a PHP-FPM setup beyond the cache clears; if you run OPcache with `validate_timestamps=0`, reload PHP-FPM:

```sh
sudo systemctl reload php8.2-fpm    # match your PHP version
```

### Optional: pre-warm the cache

Only worthwhile for a permanently displayed NOC dashboard. Deliberately not scheduled by default, so an installation that never opens the widget generates no API traffic:

```sh
# crontab, as the librenms user — match --radius to your widget setting
*/10 * * * * /usr/bin/php /opt/librenms/artisan weather-risk-map:warm --radius=10 --quiet-success
```

---

## 7. Configuration

### Widget settings (per widget, per user)

| Group | Setting | Options | Default |
| --- | --- | --- | --- |
| General | Widget title | free text | *Weather Risk Map* |
| General | Device groups | multi-select, empty = all | All |
| General | Risk period | Current / 3 / 6 / 12 / 24 / 48 h | 24 h |
| General | Minimum displayed severity | All / Moderate+ / High+ / Severe+ | All |
| General | Refresh interval | seconds (core field) | 600 |
| Map | Default map mode | Auto-fit devices / Remember last position | Auto-fit |
| Map | Weather risk radius | 1 / 5 / 10 / 25 / 50 km | 10 km |
| Map | Marker clustering | on/off | on |
| Map | Show warning polygons | on/off | on |
| Hazards | Wind, Storm, Lightning, Rain, Flood, Snow, Icing, Heat, Cold | on/off each | all on |
| Thresholds | numeric per hazard × moderate/high/severe | blank = installation default | see below |

> **Note on the refresh field:** the spec suggested a 5/10/15/30/60-minute dropdown. The panel instead inherits LibreNMS's own numeric refresh input from `widgets.settings.base`, because that is the convention every other widget follows and it is what core's save path validates. The default is 600 s (10 min) as specified. Set any value you like.

### Installation config (`config/weather-risk-map.php`)

Provider selection, User-Agent, language, cache TTLs, HTTP timeout, request budget, default thresholds, and sensitivity profiles. Every value is overridable by environment variable.

### Infrastructure sensitivity (spec §9)

Ships **empty** so nothing changes until you opt in. Profiles reference LibreNMS device groups **by numeric id**, never by name:

```php
'sensitivity' => [
    'enabled' => true,
    'profiles' => [
        'tower' => [
            'label'   => 'Radio / tower sites',
            'groups'  => [3, 7],
            'hazards' => ['wind' => 1, 'lightning' => 1, 'icing' => 1],
        ],
        'cabinet' => [
            'label'   => 'Outdoor cabinets',
            'groups'  => [11],
            'hazards' => ['heat' => 1, 'cold' => 1, 'flood' => 1],
        ],
        'datacentre' => [
            'label'   => 'Core datacentres',
            'groups'  => [1],
            'hazards' => ['wind' => -1],
        ],
    ],
],
```

Find your group ids with:

```sh
./lnms tinker --execute="App\Models\DeviceGroup::pluck('name','id')->dump();"
```

Offsets shift the hazard on the 0–5 scale, clamped at both ends. When a device is in several profiled groups the **largest** offset wins — erring toward showing more risk, never less.

---

## 8. The risk algorithm

Fully documented in the class docblock of `src/Services/WeatherRiskService.php`. In summary:

1. **Calculated risk** — every forecast hour in the window is scored against configurable thresholds per hazard; the hazard's result is the **worst hour** and the time it occurs. Wind scores sustained speed and gusts on separate scales, because a 25 m/s gust in a 12 m/s wind is a different engineering problem from steady 25 m/s.
2. **Official warning risk** — warnings whose polygon contains the device and whose validity overlaps the window contribute their published severity: yellow → Moderate, orange → High, red → Severe.
3. **Combination** — `effectiveRisk = max(calculatedRisk, officialWarningRisk)`. Official warnings raise but never lower a calculated risk; a genuinely dangerous local value is not suppressed because no warning was issued. When calculated risk wins, the assessment is still flagged `official` so the operator sees a warning exists.
4. **Sensitivity** — device-group offsets applied.
5. **Escalation** — two or more hazards at Severe promote the device to **Extreme** (purple). Compound events are what actually take sites off the air.

Default thresholds (all overridable per widget):

| Hazard | Moderate | High | Severe |
| --- | --- | --- | --- |
| Wind (sustained, m/s) | 12 | 18 | 25 |
| Wind gusts (m/s) | 17 | 24 | 32 |
| Rain (mm/h) | 4 | 8 | 15 |
| Rain accumulated (mm) | 20 | 40 | 70 |
| Snow (mm water eq.) | 5 | 12 | 25 |
| Thunder probability (%) | 15 | 40 | 70 |
| Heat (°C, at or above) | 30 | 35 | 40 |
| Cold (°C, at or below) | −10 | −20 | −30 |

Icing is derived from the physical precondition — precipitation while air temperature sits in the −6 °C…+1 °C band — and is flagged `inferred`.

---

## 9. Map and UI behaviour

- **Marker colour = weather risk** (green/blue/yellow/orange/red/purple). **LibreNMS down state = a dark double border**, and disabled devices are dimmed. The two are never conflated.
- **Clusters take the colour of their worst member**, so one red site among 26 green ones is still visible zoomed out.
- **Warning polygons** are drawn with 16 % fill opacity so markers stay readable underneath, and are pushed behind the markers. Clicking one shows type, severity, official title, description, consequences, recommendations, start/end, source and fetch time — each rendered **only if the source actually published it**. Nothing is fabricated.
- **Automatic positioning** fits the bounds of the visible devices; a single device gets zoom 11. No country or city is hard-coded.
- **Summary counters are clickable** severity filters; hazard chips toggle hazards client-side.
- **Table rows are clickable** and centre/zoom the map on that device, expanding its cluster first.
- **Dark mode** — Leaflet ships hard-coded light chrome, so popups, tips, bars, layer control and attribution are each re-declared for the dark theme, and tiles get a slight brightness reduction so markers stay legible.

---

## 10. Testing

### Automated

```sh
cd ../weather-risk-map-widget
composer install
composer test          # or: vendor/bin/phpunit --testsuite unit
composer lint          # Pint style check
```

The unit suite is pure — no database, no Laravel application, no network — and covers the specification's test matrix:

| Area | Covered by |
| --- | --- |
| Valid / missing / invalid / duplicate coordinates, `0,0`, out-of-range, NaN | `CoordinateTest` |
| Polygon containment, holes, concave shapes, MultiPolygon, malformed geometry, simplification | `PolygonTest`, `WeatherGeoServiceTest` |
| No warnings / yellow / orange / red / multiple simultaneous | `WeatherRiskServiceTest`, `MetNorwayParsingTest` |
| `max(calculated, official)` in both directions | `WeatherRiskServiceTest` |
| Thresholds, gusts, peak-hour selection, null-≠-zero | `WeatherRiskServiceTest` |
| Sensitivity offsets, positive and negative | `WeatherRiskServiceTest` |
| Device grouping, 1000 devices → few cells | `WeatherGeoServiceTest` |
| Malformed response, missing fields, unparseable times | `MetNorwayParsingTest` |
| Cached fallback, provider throw, provider null, failure backoff | `WeatherCacheServiceTest` |
| Settings validation, XSS in title, injection in group ids, range clamping | `WidgetSettingsTest` |

### Static and syntax checks

Run these on the LibreNMS host, where PHP is available:

```sh
find src -name '*.php' -exec php -l {} \;
php -l routes/web.php && php -l config/weather-risk-map.php

# Blade templates compile-check
cd /opt/librenms && php artisan view:cache && php artisan view:clear

# LibreNMS's own validation
./validate.php
```

### Manual NOC checks

1. **API success** — `php artisan weather-risk-map:diagnose` reports forecast OK and a warning count.
2. **Cached fallback** — block `api.met.no` at the firewall, refresh the widget: it must show devices plus a *"Weather data temporarily unavailable / cached data N minutes old"* badge, never an empty widget or a Laravel error page.
3. **Timeout** — set `WEATHER_RISK_MAP_TIMEOUT=1`; the widget must degrade, not hang.
4. **Rate limit** — refresh the data endpoint more than 60×/min; expect a throttled message, not a stack trace.
5. **Device UP/DOWN** — confirm a down device keeps its weather colour but gains the dark border.
6. **Device groups** — set a group filter and confirm only its members appear.
7. **Light and dark mode** — toggle the LibreNMS theme and check popups, table, controls, polygons and the loading/error overlay for contrast.
8. **Sizes** — resize the widget and the browser; the map must call `invalidateSize()` on resize (it does, via the `resize` event).
9. **Clustering** — zoom out and confirm cluster colour reflects the worst member.
10. **Hundreds of devices** — check `cells` in the widget footer stays small relative to device count.

---

## 11. Troubleshooting

Logs go to `storage/logs/weather-risk-map.log` (separate channel, so weather noise stays out of the main LibreNMS log).

| Symptom | Cause | Fix |
| --- | --- | --- |
| Widget missing from **Add Widget** | Route not registered | `php artisan route:clear`, then `php artisan route:list --path=ajax/dash` — `weather-risk-map` must appear |
| Empty map, "No devices with valid GPS coordinates" | No lat/lng on locations | `php artisan weather-risk-map:diagnose`; set coordinates, or enable LibreNMS geolocation lookup |
| Log shows `403` from MET Norway | Placeholder User-Agent, or >4-decimal coordinates | Set `WEATHER_RISK_MAP_USER_AGENT` to include a real contact email |
| Log shows `429` | Too many cells, TTL too short | Increase the widget's radius, raise `forecast_ttl`, or lower `max_cells_per_refresh` |
| "Weather data temporarily unavailable" persists | Provider unreachable from the server | Check egress to `api.met.no:443`; the widget is working as designed by showing cached data |
| Warnings never appear | `providers.warnings` set to a provider without warnings, or devices outside Norway | Set it to `met-norway`; check `diagnose` output |
| Polygons missing but warnings listed | Source published a warning without geometry | Expected — such a warning cannot be matched to a device and is listed only |
| Map is blank white/black | Leaflet failed to load | Check the browser console; confirm `html/js/leaflet.js` is served |
| Settings do not persist | Dashboard not owned/writable by the user | Core requires `update` permission on the dashboard |
| Everything stale after config edit | Config cached | `php artisan config:clear` |

Raise log verbosity temporarily with `WEATHER_RISK_MAP_LOG_LEVEL=debug`. Normal operation logs only aggregate lines (e.g. one "skipped N devices for unusable coordinates" per refresh), never one line per device.

---

## 12. Known limitations

1. **Lightning is risk, not strikes.** No free public API provides real-time strike data. Clearly labelled everywhere it appears.
2. **Official warnings are Norway-only.** MetAlerts covers Norway and its waters. Devices elsewhere still get forecast-based calculated risk; MET's forecast model is global. There is no equivalent single-request pan-European warning feed with polygons.
3. **Icing and snow are inferred** from temperature, precipitation and symbol codes rather than a dedicated icing product. Flagged `inferred`.
4. **Snow amounts are water-equivalent millimetres**, not snow depth — that is what the providers publish.
5. **No antimeridian handling** in point-in-polygon. MET Norway issues no warnings crossing 180°; the failure mode would be a missed match, never a false one.
6. **Warning polygons are simplified** to ~0.01° for transmission. Device *matching* always uses full-resolution geometry server-side; only the drawn outline is thinned.
7. **The per-refresh cell budget** means a pathological inventory (thousands of distinct sites at 1 km radius) will show some devices from cached rather than current data. The footer reports cell count so this is visible.
8. **Sensitivity is a flat offset table**, not a rules engine — by design for v1. `SensitivityProfileService::adjust()` is the seam where a richer engine slots in.
9. **Cluster "worst member" derives from `zIndexOffset`.** Compact, but it couples two concerns; a dedicated marker option would be cleaner if the marker code grows.
10. **Automated tests could not be executed in the authoring environment** — see the honesty note below.

---

## 13. Suggested future improvements

The architecture was chosen so these do not require a rewrite:

- **Lightning strike overlay** — add `getStrikes()` to `WeatherProviderInterface` and a layer in the view; the risk engine already distinguishes detected from inferred via `HazardAssessment::$inferred`.
- **Weather radar overlay** — a Leaflet tile layer; `mapConfig` already flows from controller to view.
- **Historical correlation** — the payload is already keyed by `device_id` and timestamped; joining against `eventlog` / `alert_log` for "8 network incidents occurred during this storm" needs no change to the data layer.
- **Predictive risk** — `WeatherRiskService::assess()` returns structured `HazardAssessment` objects rather than strings, which is the shape a scoring model would consume.
- **Additional providers** — implement `WeatherProviderInterface`, add one `match` arm in `WeatherRiskMapFactory::makeProvider()`. Nothing else changes.
- **Per-hazard sensitivity in the UI** rather than the config file.
- **Persisted "acknowledged risk"** so a NOC can mute a known-exposed site.

---

## 14. A note on verification

The authoring environment for this package had **no PHP runtime, no Composer, no Docker and no WSL**, so the following could **not** be executed here and are unverified by running:

- `php -l` syntax checks
- the PHPUnit suite
- Blade compilation
- `./validate.php`

The code was written against the actual LibreNMS checkout in `../librenms` and its real APIs — `DashboardController::listWidgets()`, `WidgetController`, `Device`/`Location`/`DeviceGroup` models, `scopeInDeviceGroup`, `scopeHasAccess`, `init_map()`, `widget_settings()` — each read directly from source rather than assumed, and the MET Norway contracts were verified against the live API documentation rather than recalled. But please run section 10's commands on the LibreNMS host before putting this in front of a NOC, and treat the first `weather-risk-map:diagnose` as the real acceptance test.
#   w e a t h e r - r i s k - m a p - w i d g e t  
 