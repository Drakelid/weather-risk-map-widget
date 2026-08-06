# Weather Risk Map

**A LibreNMS dashboard widget that shows which network sites are about to be hit by bad weather.**

It reads the GPS coordinates already stored on your LibreNMS devices, correlates them with forecasts and official severe-weather warnings, and reports an **infrastructure risk level** — not a weather report.

| | |
| --- | --- |
| **LibreNMS** | `26.7.0-68-g1dfe1994d` |
| **PHP** | `^8.2` |
| **Laravel** | `^12.10` |
| **Core files modified** | **none** |
| **Database changes** | **none** |
| **JS dependencies** | **none** (Leaflet ships with LibreNMS) |
| **Licence** | GPL-3.0-or-later |

---

## Contents

1. [Quick start](#1-quick-start)
2. [Risk levels](#2-risk-levels)
3. [How it works](#3-how-it-works)
4. [Weather data sources](#4-weather-data-sources)
5. [Installation](#5-installation)
6. [Configuration](#6-configuration)
7. [The risk algorithm](#7-the-risk-algorithm)
8. [Map and UI behaviour](#8-map-and-ui-behaviour)
9. [Testing](#9-testing)
10. [Troubleshooting](#10-troubleshooting)
11. [Files created and modified](#11-files-created-and-modified)
12. [Known limitations](#12-known-limitations)
13. [Future improvements](#13-future-improvements)
14. [Verification status](#14-verification-status)

---

## 1. Quick start

From the LibreNMS directory, as the `librenms` user:

```sh
composer config repositories.weather-risk-map \
  '{"type":"path","url":"../weather-risk-map-widget","options":{"symlink":true}}'

./lnms plugin:add librenms/weather-risk-map-widget @dev
php artisan vendor:publish --tag=weather-risk-map-config
```

Add **one required line** to `.env`:

```dotenv
WEATHER_RISK_MAP_USER_AGENT="YourISP-LibreNMS/1.0 noc@yourisp.no"
```

Then clear caches and verify:

```sh
php artisan config:clear && php artisan route:clear && php artisan view:clear
php artisan weather-risk-map:diagnose
```

Add it to a dashboard: **Overview → edit (pencil) → Add Widget → Weather Risk Map**.

> [!IMPORTANT]
> The `.env` line is not optional. MET Norway answers **403** to any request whose `User-Agent` lacks a real contact email. `weather-risk-map:diagnose` fails loudly while the shipped placeholder is still in place.

Full details in [§5 Installation](#5-installation).

---

## 2. Risk levels

| | Level | Meaning |
| :-: | --- | --- |
| 🟢 | **Normal** | No significant risk |
| 🔵 | **Info** | Informational / minor weather |
| 🟡 | **Moderate** | Yellow-warning territory |
| 🟠 | **High** | Orange-warning territory |
| 🔴 | **Severe** | Red-warning territory |
| 🟣 | **Extreme** | Two or more hazards at Severe |

> [!NOTE]
> Marker **fill** is weather risk. LibreNMS **operational status** is drawn separately — a device that is down keeps its weather colour and gains a dark border. The two are never conflated.

---

## 3. How it works

```
 Browser (dashboard)
   │  POST ajax/dash/weather-risk-map     → renders the shell only, no weather I/O
   │  POST plugin/weather-risk-map/data   → asynchronous payload
   ▼
 WeatherRiskDataController
   └── WeatherRiskMapFactory              per-widget wiring (radius + thresholds vary)
         └── WeatherRiskMapBuilder        orchestration
               ├── DeviceLocationService      devices + coordinates from LibreNMS
               ├── WeatherGeoService          grid cells, point-in-polygon, bounds
               ├── WeatherRiskService         thresholds, official warnings, scoring
               ├── SensitivityProfileService  per-device-group hazard offsets
               └── WeatherProviderInterface
                     ├── MetNorwayWeatherProvider   forecast + official warnings
                     └── OpenMeteoWeatherProvider   forecast (batched, worldwide)
                           └── WeatherCacheService  two-tier cache + stale fallback
```

### The widget renders before its data

The dashboard requests each widget over AJAX and swaps in the returned HTML. If the widget controller waited on the weather APIs, a slow provider would visibly stall it.

So the widget controller performs **no weather I/O at all**. The shell paints immediately and fetches its payload separately.

### Device count does not drive API traffic

Three collapses happen in sequence:

| # | Collapse | Effect |
| :-: | --- | --- |
| 1 | Devices → LibreNMS `locations` rows | A POP with 40 switches is **one** coordinate |
| 2 | Locations → geographic grid cells | Sites a forecast cannot distinguish share one lookup |
| 3 | Warnings → **one national request** | Matched to devices locally, point-in-polygon |

A 2,000-device Norwegian ISP across ~150 sites typically resolves to **30–60 forecast requests per 15 minutes**, plus **one warnings request per 5 minutes**. Both are cached, so a NOC wall with eight browsers open generates the same traffic as one.

A hard `max_cells_per_refresh` ceiling bounds the worst case. Cells beyond it reuse cached data rather than being dropped.

### The cache has two tiers

Every entry is written twice:

- **`fresh:`** — short TTL, the normal serving copy
- **`stale:`** — long TTL, the failure fallback

A single-tier cache loses its data exactly when the provider goes down and you need it most. On failure the widget serves the stale copy and labels it with its age. A failure marker additionally stops a dead provider being retried by every dashboard refresh from every logged-in operator.

---

## 4. Weather data sources

### MET Norway — primary (`met-norway`)

| Product | Endpoint | Used for |
| --- | --- | --- |
| Locationforecast 2.0 | `…/locationforecast/2.0/compact` | Point forecast, per geo cell |
| MetAlerts 2.0 | `…/metalerts/2.0/current.json` | **Every** active Norwegian warning as GeoJSON polygons, in **one request** |

Chosen because it is authoritative for Norway, it is the *only* candidate publishing official warning polygons with severity, and MetAlerts' single-request national coverage is what makes local polygon matching possible.

Terms-of-service compliance is **implemented**, not just intended:

- ✅ Identifying `User-Agent` with a contact email — MET returns **403** without one
- ✅ Coordinates truncated to **4 decimals** — MET returns **403** with more
- ✅ `Expires` honoured — `heldByExpires()` refuses to re-request inside the window even when the local TTL is shorter
- ✅ `If-Modified-Since` sent with the exact previous `Last-Modified`; a `304` refreshes the TTL without re-downloading
- ✅ 20 req/s application-wide — geo-cell grouping keeps us orders of magnitude below it
- ✅ CC BY 4.0 attribution rendered in the widget footer

### Open-Meteo — secondary (`open-meteo`)

Kept behind the same interface for two reasons:

- Its forecast endpoint accepts **comma-separated coordinate lists**, so all cells arrive in one request.
- It gives **worldwide coverage** for devices outside MET Norway's warning area.

It publishes no official warnings, so `supportsWarnings()` returns `false` and warnings keep coming from MET Norway.

### Lightning — an honest limitation

> [!WARNING]
> No free, reliable public API provides real-time lightning **strike** data.

Lightning is modelled as **risk** from thunder indications in the forecast — `probability_of_thunder` where available, otherwise the `…andthunder` symbol suffix. It is flagged `inferred` in the payload and rendered as *"(risk, not detected strikes)"* in the UI. Nothing is presented as a detected strike.

---

## 5. Installation

Leaflet and Leaflet.markercluster are **already bundled with LibreNMS** (`html/js/leaflet.js`, `html/js/leaflet.markercluster.js`), and the widget reuses core's `init_map()` / `get_map()` / `destroy_map()` helpers. There are no JavaScript dependencies and no asset build step.

### Steps

```sh
# 1. Register this directory as a Composer path repository
composer config repositories.weather-risk-map \
  '{"type":"path","url":"../weather-risk-map-widget","options":{"symlink":true}}'

# 2. Install the package
./lnms plugin:add librenms/weather-risk-map-widget @dev

# 3. Publish the config so it survives package updates
php artisan vendor:publish --tag=weather-risk-map-config
```

**4. Set the contact email** — required, see the note in [§1](#1-quick-start):

```dotenv
# .env
WEATHER_RISK_MAP_USER_AGENT="YourISP-LibreNMS/1.0 noc@yourisp.no"
```

```sh
# 5. Clear caches
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear

# 6. Verify end to end
php artisan weather-risk-map:diagnose
```

Then: **Overview → dashboard → edit (pencil) → Add Widget → Weather Risk Map**.

### Notes

- If your installation caches config or routes, re-run `php artisan config:cache` / `route:cache` after step 5.
- No service restart is needed beyond the cache clears — unless you run OPcache with `validate_timestamps=0`, in which case reload PHP-FPM:
  ```sh
  sudo systemctl reload php8.2-fpm    # match your PHP version
  ```

### Optional: pre-warm the cache

Worth it only for a permanently displayed NOC dashboard. Deliberately **not** scheduled by default, so an installation that never opens the widget generates no API traffic:

```sh
# crontab, as the librenms user — match --radius to your widget setting
*/10 * * * * /usr/bin/php /opt/librenms/artisan weather-risk-map:warm --radius=10 --quiet-success
```

---

## 6. Configuration

### Widget settings

Per widget, per user — edited in the widget's own settings panel.

| Group | Setting | Options | Default |
| --- | --- | --- | --- |
| **General** | Widget title | free text | *Weather Risk Map* |
| | Device groups | multi-select, empty = all | All |
| | Risk period | Current / 3 / 6 / 12 / 24 / 48 h | **24 h** |
| | Minimum displayed severity | All / Moderate+ / High+ / Severe+ | All |
| | Refresh interval | seconds | 600 |
| **Map** | Default map mode | Auto-fit devices / Remember last position | Auto-fit |
| | Weather risk radius | 1 / 5 / 10 / 25 / 50 km | 10 km |
| | Marker clustering | on / off | on |
| | Show warning polygons | on / off | on |
| **Hazards** | Wind, Storm, Lightning, Rain, Flood, Snow, Icing, Heat, Cold | on / off each | all on |
| **Thresholds** | numeric, per hazard × moderate/high/severe | blank = installation default | [see §7](#7-the-risk-algorithm) |

> [!NOTE]
> **On the refresh field.** The spec suggested a 5/10/15/30/60-minute dropdown. The panel instead inherits LibreNMS's own numeric refresh input from `widgets.settings.base`, because that is the convention every other widget follows and what core's save path validates. The default is 600 s (10 min) as specified; any value is accepted.

### Installation config

`config/weather-risk-map.php` — provider selection, User-Agent, language, cache TTLs, HTTP timeout, request budget, default thresholds and sensitivity profiles. Every value is overridable by environment variable.

### Infrastructure sensitivity

Shifts individual hazards up or down for devices in a given LibreNMS device group. Ships **empty**, so nothing changes until you opt in.

Profiles reference device groups **by numeric id**, never by name:

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

Offsets shift the hazard on the 0–5 scale and are clamped at both ends. When a device belongs to several profiled groups the **largest** offset wins — erring toward showing more risk, never less.

---

## 7. The risk algorithm

Fully documented in the class docblock of [`src/Services/WeatherRiskService.php`](src/Services/WeatherRiskService.php). In summary:

**1 — Calculated risk.** Every forecast hour in the window is scored against configurable thresholds per hazard. The result is the **worst hour** and the time it occurs. Wind scores sustained speed and gusts on separate scales, because a 25 m/s gust in a 12 m/s wind is a different engineering problem from steady 25 m/s.

**2 — Official warning risk.** Warnings whose polygon contains the device, and whose validity overlaps the window, contribute their published severity: yellow → Moderate, orange → High, red → Severe.

**3 — Combination.**

```
effectiveRisk = max(calculatedRisk, officialWarningRisk)
```

Official warnings **raise but never lower** a calculated risk — a genuinely dangerous local value is not suppressed just because no warning was issued. When calculated risk wins, the assessment is still flagged `official` so the operator sees a warning exists.

**4 — Sensitivity.** Device-group offsets applied.

**5 — Escalation.** Two or more hazards at Severe promote the device to **Extreme** 🟣. Compound events are what actually take sites off the air.

### Default thresholds

All overridable per widget.

| Hazard | 🟡 Moderate | 🟠 High | 🔴 Severe |
| --- | :-: | :-: | :-: |
| Wind, sustained (m/s) | 12 | 18 | 25 |
| Wind gusts (m/s) | 17 | 24 | 32 |
| Rain (mm/h) | 4 | 8 | 15 |
| Rain, accumulated (mm) | 20 | 40 | 70 |
| Snow (mm water eq.) | 5 | 12 | 25 |
| Thunder probability (%) | 15 | 40 | 70 |
| Heat (°C, at or above) | 30 | 35 | 40 |
| Cold (°C, at or below) | −10 | −20 | −30 |

Icing is derived from the physical precondition — precipitation while air temperature sits in the **−6 °C … +1 °C** band — and is flagged `inferred`.

---

## 8. Map and UI behaviour

- **Marker colour = weather risk.** LibreNMS down state is a dark double border; disabled devices are dimmed. Never conflated.
- **Clusters take the colour of their worst member**, so one red site among 26 green ones stays visible when zoomed out.
- **Warning polygons** use 16 % fill opacity and sit behind the markers, so devices stay readable underneath.
- **Warning popups** show type, severity, official title, description, consequences, recommendations, start/end, source and fetch time — each rendered **only if the source actually published it**. Nothing is fabricated.
- **Automatic positioning** fits the bounds of the visible devices; a single device gets zoom 11. No country or city is hard-coded.
- **Summary counters are clickable** severity filters; hazard chips toggle hazards client-side.
- **Table rows are clickable** and centre/zoom the map on that device, expanding its cluster first.
- **Dark mode.** Leaflet ships hard-coded light chrome, so popups, tips, bars, layer control and attribution are each re-declared for the dark theme, and tiles get a slight brightness reduction so markers stay legible.

---

## 9. Testing

### Automated

```sh
cd weather-risk-map-widget
composer install
composer test     # or: vendor/bin/phpunit --testsuite unit
composer lint     # Pint style check
```

The unit suite is pure — no database, no Laravel application, no network.

<details>
<summary><strong>Coverage against the specification's test matrix</strong></summary>

| Area | Covered by |
| --- | --- |
| Valid / missing / invalid / duplicate coordinates, `0,0`, out-of-range, NaN | `CoordinateTest` |
| Polygon containment, holes, concave shapes, MultiPolygon, malformed geometry, simplification | `PolygonTest`, `WeatherGeoServiceTest` |
| No warnings / yellow / orange / red / multiple simultaneous | `WeatherRiskServiceTest`, `MetNorwayParsingTest` |
| `max(calculated, official)` in both directions | `WeatherRiskServiceTest` |
| Thresholds, gusts, peak-hour selection, null ≠ zero | `WeatherRiskServiceTest` |
| Sensitivity offsets, positive and negative | `WeatherRiskServiceTest` |
| Device grouping, 1000 devices → few cells | `WeatherGeoServiceTest` |
| Malformed response, missing fields, unparseable times | `MetNorwayParsingTest` |
| Cached fallback, provider throw, provider null, failure backoff | `WeatherCacheServiceTest` |
| Settings validation, XSS in title, injection in group ids, range clamping | `WidgetSettingsTest` |

</details>

### Static and syntax checks

Run on the LibreNMS host, where PHP is available:

```sh
find src -name '*.php' -exec php -l {} \;
php -l routes/web.php && php -l config/weather-risk-map.php

# Blade templates compile-check
cd /opt/librenms && php artisan view:cache && php artisan view:clear

# LibreNMS's own validation
./validate.php
```

### Manual NOC checks

| # | Check | Expected |
| :-: | --- | --- |
| 1 | `php artisan weather-risk-map:diagnose` | Forecast OK, warning count reported |
| 2 | Block `api.met.no` at the firewall, refresh | Devices still shown + *"cached data N minutes old"* badge — never an empty widget or a Laravel error page |
| 3 | Set `WEATHER_RISK_MAP_TIMEOUT=1` | Widget degrades, does not hang |
| 4 | Hit the data endpoint > 60×/min | Throttled message, not a stack trace |
| 5 | Take a device down | Keeps its weather colour, gains the dark border |
| 6 | Set a device-group filter | Only that group's members appear |
| 7 | Toggle light / dark theme | Popups, table, controls, polygons, overlays all legible |
| 8 | Resize widget and browser | Map calls `invalidateSize()` via the `resize` event |
| 9 | Zoom out | Cluster colour reflects its worst member |
| 10 | Hundreds of devices | `cells` in the footer stays small relative to device count |

---

## 10. Troubleshooting

Logs go to `storage/logs/weather-risk-map.log` — a separate channel, so weather noise stays out of the main LibreNMS log.

| Symptom | Likely cause | Fix |
| --- | --- | --- |
| Widget missing from **Add Widget** | Route not registered | `php artisan route:clear`, then `php artisan route:list --path=ajax/dash` — `weather-risk-map` must appear |
| *"No devices with valid GPS coordinates"* | No lat/lng on locations | Run `weather-risk-map:diagnose`; set coordinates or enable LibreNMS geolocation lookup |
| `403` from MET Norway | Placeholder User-Agent, or >4-decimal coordinates | Set `WEATHER_RISK_MAP_USER_AGENT` to include a real contact email |
| `429` from a provider | Too many cells, TTL too short | Increase the widget's radius, raise `forecast_ttl`, or lower `max_cells_per_refresh` |
| *"Weather data temporarily unavailable"* persists | Provider unreachable from the server | Check egress to `api.met.no:443` — the widget is working as designed by showing cached data |
| Warnings never appear | `providers.warnings` set to a provider without warnings, or devices outside Norway | Set it to `met-norway`; check `diagnose` output |
| Polygons missing, warnings still listed | Source published a warning without geometry | **Expected** — such a warning cannot be matched to a device, so it is listed only |
| Map blank white/black | Leaflet failed to load | Check the browser console; confirm `html/js/leaflet.js` is served |
| Settings do not persist | Dashboard not writable by this user | Core requires `update` permission on the dashboard |
| Everything stale after a config edit | Config cached | `php artisan config:clear` |

Raise verbosity temporarily with `WEATHER_RISK_MAP_LOG_LEVEL=debug`. Normal operation logs only aggregate lines — e.g. one *"skipped N devices for unusable coordinates"* per refresh — never one line per device.

---

## 11. Files created and modified

### Modified: none

LibreNMS builds its widget catalogue in `DashboardController::listWidgets()` by scanning the route table for routes prefixed exactly `ajax/dash` — the `widgets` database table was dropped in 2022. Registering a route with that prefix from this package's own service provider is therefore enough to make **Weather Risk Map** appear in the Add Widget menu.

No core controller, view, route file, language file or migration is touched, so **a LibreNMS upgrade cannot overwrite the feature**.

Two details make it behave like a built-in widget rather than a bolted-on approximation:

- **View naming.** The package view directory is registered with `View::addLocation()` as well as a namespace, so views resolve as `widgets.weather-risk-map` and `widgets.settings.weather-risk-map`. Core decides whether a widget is in settings mode with `Str::startsWith($view->getName(), 'widgets.settings.')`; a namespaced name fails that test and the settings panel would silently drop out of edit mode on the next auto-refresh, discarding unsaved edits. Core's own view paths are registered first, so this cannot shadow a LibreNMS view.
- **`data-reload="false"`** on the widget's outer element makes core's refresh timer dispatch a `refresh` event instead of re-fetching and re-injecting HTML. The Leaflet map is built once and updated in place, not destroyed and recreated every cycle.

### Database changes: none

No migrations, no new tables, no new columns. Widget settings use LibreNMS's existing per-user `users_widgets.settings` JSON column; coordinates are read from the existing `locations` table. **This package stores no device coordinates of its own.**

### Created

<details>
<summary><strong>Full file inventory</strong> (37 files)</summary>

#### Package root

| File | Purpose |
| --- | --- |
| `composer.json` | Package definition, PSR-4, Laravel provider discovery |
| `phpunit.xml` | Test configuration |
| `README.md` | This document |
| `config/weather-risk-map.php` | Installation defaults: providers, User-Agent, TTLs, thresholds, sensitivity |
| `routes/web.php` | Widget route (`ajax/dash` prefix) + data endpoint |
| `lang/en/widget.php` | All user-facing strings |

#### Domain

| File | Purpose |
| --- | --- |
| `src/WeatherRiskMapServiceProvider.php` | Registration, log channel, bindings |
| `src/Contracts/WeatherProviderInterface.php` | Provider contract |
| `src/Enums/RiskLevel.php` | 0–5 risk scale, colours, labels |
| `src/Enums/HazardType.php` | Hazard classes and ISP impact notes |
| `src/DTO/Coordinate.php` | Validated WGS84 coordinate — the single place validity is decided |
| `src/DTO/ForecastPoint.php` | One forecast hour |
| `src/DTO/Forecast.php` | Time series + windowing |
| `src/DTO/WeatherWarning.php` | Official warning + geometry |
| `src/DTO/HazardAssessment.php` | Peak of one hazard, official/inferred flags |
| `src/DTO/WidgetSettings.php` | Validation and normalisation of untrusted widget settings |
| `src/Support/Polygon.php` | Ray-casting point-in-polygon, bbox fast path, holes, simplification |
| `src/Support/GeoMath.php` | Haversine distance, degree/km conversion |

#### Providers and services

| File | Purpose |
| --- | --- |
| `src/Providers/MetNorwayWeatherProvider.php` | MET Norway forecast + MetAlerts |
| `src/Providers/OpenMeteoWeatherProvider.php` | Open-Meteo batched forecast |
| `src/Services/WeatherCacheService.php` | Two-tier cache, stale fallback, failure backoff |
| `src/Services/WeatherGeoService.php` | Cell grouping, warning matching, bounds |
| `src/Services/WeatherRiskService.php` | **The documented risk algorithm** |
| `src/Services/SensitivityProfileService.php` | Per-device-group hazard offsets |
| `src/Services/DeviceLocationService.php` | Device + coordinate retrieval (no N+1) |
| `src/Services/WeatherRiskMapBuilder.php` | Orchestration, degradation handling |
| `src/Services/WeatherRiskMapFactory.php` | Per-widget service wiring |

#### HTTP, console and views

| File | Purpose |
| --- | --- |
| `src/Http/Controllers/WeatherRiskMapWidgetController.php` | The widget (extends core `WidgetController`) |
| `src/Http/Controllers/WeatherRiskDataController.php` | Async data endpoint |
| `src/Console/DiagnoseCommand.php` | End-to-end health check |
| `src/Console/WarmCacheCommand.php` | Optional cache pre-warming |
| `resources/views/widgets/weather-risk-map.blade.php` | Map, summary header, risk table, dark-mode CSS |
| `resources/views/widgets/settings/weather-risk-map.blade.php` | Settings panel |

#### Tests

| File | Purpose |
| --- | --- |
| `tests/Support/ArrayCacheStub.php` | In-memory cache for unit tests |
| `tests/Unit/CoordinateTest.php` | Coordinate validation |
| `tests/Unit/PolygonTest.php` | Point-in-polygon geometry |
| `tests/Unit/WeatherGeoServiceTest.php` | Cell grouping, warning matching, bounds |
| `tests/Unit/WeatherRiskServiceTest.php` | The risk algorithm |
| `tests/Unit/WeatherCacheServiceTest.php` | Degradation and fallback |
| `tests/Unit/MetNorwayParsingTest.php` | Provider response parsing |
| `tests/Unit/WidgetSettingsTest.php` | Settings validation and sanitisation |

</details>

---

## 12. Known limitations

1. **Lightning is risk, not strikes.** No free public API provides real-time strike data. Labelled as such everywhere it appears.
2. **Official warnings are Norway-only.** MetAlerts covers Norway and its waters. Devices elsewhere still get forecast-based calculated risk — MET's forecast model is global. There is no equivalent single-request pan-European warning feed with polygons.
3. **Icing and snow are inferred** from temperature, precipitation and symbol codes rather than a dedicated icing product. Flagged `inferred`.
4. **Snow amounts are water-equivalent millimetres**, not snow depth — that is what the providers publish.
5. **No antimeridian handling** in point-in-polygon. MET Norway issues no warnings crossing 180°; the failure mode would be a missed match, never a false one.
6. **Warning polygons are simplified** to ~0.01° for transmission. Device *matching* always uses full-resolution geometry server-side; only the drawn outline is thinned.
7. **The per-refresh cell budget** means a pathological inventory (thousands of distinct sites at 1 km radius) will show some devices from cached rather than current data. The footer reports cell count, so this is visible.
8. **Sensitivity is a flat offset table**, not a rules engine — by design for v1. `SensitivityProfileService::adjust()` is the seam where a richer engine slots in.
9. **Cluster "worst member" derives from `zIndexOffset`.** Compact, but it couples two concerns; a dedicated marker option would be cleaner if the marker code grows.
10. **The test suite has not been executed** — see [§14](#14-verification-status).

---

## 13. Future improvements

The architecture was chosen so none of these require a rewrite:

| Feature | What it needs |
| --- | --- |
| **Lightning strike overlay** | Add `getStrikes()` to `WeatherProviderInterface` + a map layer. The risk engine already distinguishes detected from inferred via `HazardAssessment::$inferred`. |
| **Weather radar overlay** | A Leaflet tile layer; `mapConfig` already flows from controller to view. |
| **Historical correlation** | The payload is already keyed by `device_id` and timestamped. Joining against `eventlog` / `alert_log` for *"8 network incidents occurred during this storm"* needs no data-layer change. |
| **Predictive risk** | `WeatherRiskService::assess()` returns structured `HazardAssessment` objects rather than strings — the shape a scoring model would consume. |
| **Additional providers** | Implement `WeatherProviderInterface`, add one `match` arm in `WeatherRiskMapFactory::makeProvider()`. Nothing else changes. |
| **Per-hazard sensitivity in the UI** | Move the config-file profiles into the settings panel. |
| **Acknowledged risk** | Persist a mute flag so a NOC can silence a known-exposed site. |

---

## 14. Verification status

> [!CAUTION]
> The authoring environment had **no PHP runtime, no Composer, no Docker and no WSL**. The following were **not executed** and are unverified by running:
> - `php -l` syntax checks
> - the PHPUnit suite
> - Blade compilation
> - `./validate.php`

What *was* done instead:

- Every core API this package depends on was **read directly from the LibreNMS checkout** rather than assumed — `DashboardController::listWidgets()`, `WidgetController`, the `Device` / `Location` / `DeviceGroup` models, `scopeInDeviceGroup`, `scopeHasAccess`, `init_map()`, `widget_settings()`.
- The MET Norway contracts were **verified against the live API documentation**, not recalled from memory.
- A structural parser checked all PHP files for balanced delimiters, open tags and stray non-ASCII — clean. That catches syntax damage, not type errors.

**Please run [§9](#9-testing) on the LibreNMS host before putting this in front of a NOC**, and treat the first `weather-risk-map:diagnose` as the real acceptance test.

---

## Attribution

Weather data from [MET Norway](https://api.met.no/) and [Open-Meteo](https://open-meteo.com/), both licensed **CC BY 4.0**. Attribution is rendered in the widget footer at runtime, as their licences require.
