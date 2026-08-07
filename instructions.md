# Build a LibreNMS Dashboard Widget: Weather Risk Map

You are working on an existing LibreNMS installation used by an ISP to monitor routers, switches, POPs, access nodes, transmission equipment, and other network infrastructure.

Create a production-quality custom LibreNMS dashboard widget called:

**Weather Risk Map**

The purpose of the widget is to use the GPS coordinates already stored on LibreNMS devices and correlate those locations with current and upcoming weather hazards.

The NOC should be able to immediately see which network devices, sites, and POPs may be affected by:

* Strong wind
* Storms
* Heavy rain
* Lightning/thunderstorms
* Extreme heat
* Extreme cold
* Snow
* Freezing rain / icing
* Flood-related weather warnings
* Other relevant severe-weather warnings provided by the selected weather API

The widget must be useful in a real ISP NOC, not merely demonstrate weather information.

---

# 1. Inspect the Existing LibreNMS Environment First

Before writing code:

1. Inspect the LibreNMS version and existing code structure.
2. Determine how custom dashboard widgets are implemented in this installation.
3. Locate existing custom widgets and use them as examples.
4. Determine the correct:

   * Controller location
   * Blade/view location
   * Widget registration mechanism
   * Routes
   * Settings/configuration handling
   * AJAX refresh mechanism
   * Authentication/authorization conventions
   * Dark/light mode CSS conventions
5. Do not modify LibreNMS core files unnecessarily.
6. Implement the feature so normal LibreNMS upgrades have the lowest possible chance of overwriting it.

If an existing custom widget architecture is already being used, follow that architecture rather than inventing a new one.

---

# 2. Device Coordinate Source

Use GPS coordinates stored in LibreNMS for each device.

Determine from the existing database/model/API how LibreNMS stores:

* latitude
* longitude
* location
* device hostname
* display name
* device ID
* device group
* operational status

Only devices with valid latitude and longitude should appear on the map.

Ignore coordinates such as:

* null
* empty
* `0,0`
* invalid latitude
* invalid longitude

Validate:

* latitude: -90 through 90
* longitude: -180 through 180

The coordinate information must come from LibreNMS. Do not maintain a second device-coordinate database.

---

# 3. Weather Data Source

Select an appropriate reliable weather API.

Because this LibreNMS installation primarily monitors infrastructure in Norway, strongly prefer official or authoritative Norwegian/European weather sources where practical.

Investigate options such as:

* MET Norway APIs
* MET Norway weather warnings
* Open-Meteo
* official severe-weather warning feeds

Prefer sources that:

* allow coordinate-based lookups
* provide severe-weather warnings
* have reasonable usage limits
* do not require expensive commercial licenses
* have good coverage in Norway
* provide documented APIs

Different APIs may be used for different purposes if necessary.

For example:

* forecast API
* official warnings API
* lightning/thunderstorm information

Do not invent data.

If true real-time lightning-strike data cannot be obtained from a reliable free/public API, implement thunderstorm/lightning **risk** based on available weather data and clearly label it accordingly.

Keep the weather provider implementation modular so another provider can be added later.

---

# 4. Do NOT Query Weather Once Per Device

This is extremely important.

An ISP may have hundreds or thousands of devices.

Do NOT make one external weather API request per device every time the dashboard refreshes.

Implement an efficient architecture.

Possible approaches include:

* grouping nearby devices geographically
* spatial grid/geohash caching
* querying warning polygons once and performing local point-in-polygon matching
* caching forecasts by geographic area
* caching API responses

Use the best approach based on the selected APIs.

External weather requests must be cached.

Suggested defaults:

Forecast:

* cache 10–20 minutes

Official warnings:

* cache 5–10 minutes

Do not block loading the entire LibreNMS dashboard while waiting for dozens of external requests.

---

# 5. Map

Create an interactive geographic map.

Prefer a LibreNMS-compatible open-source map solution such as:

**Leaflet + OpenStreetMap**

unless LibreNMS already provides a mapping component that makes more sense.

The map should display all qualifying devices.

Each device should have a marker.

Marker colour should represent weather risk:

* Green = No significant risk
* Blue = Informational/minor weather
* Yellow = Moderate
* Orange = High
* Red = Severe
* Purple = Extreme / multiple severe hazards

Devices that are down in LibreNMS should still be visible but visually distinguishable.

For example:

* weather colour = marker fill
* device-down status = dark border / X / special icon

Do not confuse weather risk with LibreNMS operational status.

---

# 6. Weather Warning Areas

Where the weather API provides warning polygons or geographical warning regions, overlay these areas on the map.

Example colours:

* Yellow warning → transparent yellow polygon
* Orange warning → transparent orange polygon
* Red warning → transparent red polygon

Use partially transparent fills so devices remain visible underneath.

Clicking a warning region should display:

* Warning type
* Severity
* Official title
* Description
* Start time
* End time
* Source
* Last updated
* Relevant recommendations if provided by the source

Do not fabricate recommendations.

---

# 7. Device Marker Popup

Clicking a device must open a useful popup.

Example:

**hk-core01**

Status: UP
Location: Haugesund POP
Weather Risk: HIGH

Current:

* Temperature: 7°C
* Wind: 18 m/s
* Gusts: 27 m/s
* Precipitation: Heavy rain

Warnings:

* 🟠 Strong wind
* 🟡 Heavy rainfall

Warning period:
14:00–21:00

Potential ISP impact:

* High wind exposure

Include links where possible to:

* LibreNMS device page
* relevant warning source

---

# 8. ISP-Oriented Risk Logic

Do not simply display a weather forecast.

Calculate an infrastructure-oriented **Weather Risk Level**.

Risk calculation should be configurable and understandable.

Examples:

## Wind

Default thresholds could start around:

* < 12 m/s → normal
* 12–18 m/s → moderate
* 18–25 m/s → high
* > 25 m/s → severe

Consider gusts separately.

## Temperature

Configurable thresholds such as:

Heat:

* > 30°C
* > 35°C

Cold:

* <-10°C
* <-20°C

Do not hard-code thresholds permanently; expose them in widget configuration.

## Precipitation

Use API-provided intensity/warnings wherever possible.

## Lightning

If actual lightning information is available:

* nearby strikes
* strike density
* strike distance

Otherwise use thunderstorm/lightning-risk information from the forecast and clearly state that it is risk rather than detected strikes.

## Official warnings

Official government/MET warnings should generally override calculated local thresholds when their severity is higher.

For example:

`effectiveRisk = max(calculatedRisk, officialWarningRisk)`

Document the risk algorithm in the code.

---

# 9. ISP Infrastructure Sensitivity

Add an optional configuration system allowing certain LibreNMS device groups to have different weather sensitivity.

Example:

### Radio / tower sites

High sensitivity to:

* wind
* lightning

### Outdoor cabinets

High sensitivity to:

* heat
* cold
* flooding

### Core datacentres

Lower wind sensitivity

### Mountain sites

High sensitivity to:

* snow
* icing
* wind

The initial version does not need an enormous rules engine, but structure the code so this capability can be expanded later.

---

# 10. Device Groups

Widget configuration must support:

**Device Group filter**

Options:

* All devices
* One device group
* Multiple device groups if practical with the current LibreNMS widget settings framework

The map must then only show devices belonging to the selected groups.

Do not rely on matching group names manually if LibreNMS provides proper group IDs/relationships.

---

# 11. Risk Filter

Add configurable display filters:

Show:

* All
* Moderate+
* High+
* Severe only

Also allow toggling individual weather types:

☑ Wind
☑ Storm
☑ Lightning
☑ Heavy rain
☑ Flood warning
☑ Snow
☑ Icing
☑ Extreme heat
☑ Extreme cold

---

# 12. Time Window

Allow choosing the risk period:

* Current
* Next 3 hours
* Next 6 hours
* Next 12 hours
* Next 24 hours
* Next 48 hours

Default:

**Next 24 hours**

For each device, display the highest expected risk within the selected period.

---

# 13. Summary Header

Above the map create an ISP/NOC summary.

Example:

WEATHER RISK — NEXT 24 HOURS

🔴 3 devices Severe
🟠 12 devices High
🟡 34 devices Moderate
🟢 281 devices Normal

Active warnings: 5

Highest risk:
**Strong wind — Haugalandet — 14:00–21:00**

Make the counters clickable if practical so clicking "High" filters the map.

---

# 14. Affected Infrastructure Panel

Add either a panel beside/below the map showing devices at risk.

Example:

| Risk | Device      | Location | Hazard    | Peak        | Time  |
| ---- | ----------- | -------- | --------- | ----------- | ----- |
| 🔴   | tower-hgs01 | Karmøy   | Wind      | 31 m/s gust | 16:00 |
| 🟠   | pop-etne01  | Etne     | Rain      | 34 mm       | 19:00 |
| 🟠   | radio01     | Bokn     | Lightning | High risk   | 17:00 |
| 🟡   | sw01        | Odda     | Cold      | -14°C       | 04:00 |

Support sorting by:

* severity
* device
* location
* hazard
* warning start time

Clicking a device should centre/zoom the map to that device.

---

# 15. Cluster Markers

LibreNMS may monitor many devices in the same POP.

Implement marker clustering.

When zoomed out:

`27`

could represent 27 devices in that region.

Cluster styling should indicate the highest risk within the cluster.

Example:

A cluster containing:

* 15 green
* 8 yellow
* 3 orange
* 1 red

should visually indicate red/severe.

When clicked, zoom into the individual devices.

---

# 16. Geographical Radius / Nearby Weather

For point-based forecasts, avoid requiring exact warning coordinates to match a device.

Support a configurable radius where appropriate.

Example:

`Weather risk radius: 10 km`

Possible options:

* 1 km
* 5 km
* 10 km
* 25 km
* 50 km

However, use official warning polygons directly when polygon information exists.

---

# 17. Widget Configuration

The widget should expose settings similar to:

### General

Title:
`Weather Risk Map`

Device Groups:
`All`

Refresh:

* 5 min
* 10 min
* 15 min
* 30 min
* 60 min

Default:
`10 min`

Risk period:
`24 hours`

Minimum displayed severity:
`All`

### Map

Default map mode:

* Auto-fit devices
* Remember last position

Marker clustering:
`Enabled`

Show warning polygons:
`Enabled`

### Hazards

Wind:
`Enabled`

Lightning:
`Enabled`

Rain:
`Enabled`

Flood:
`Enabled`

Snow:
`Enabled`

Icing:
`Enabled`

Heat:
`Enabled`

Cold:
`Enabled`

### Thresholds

Allow configurable numeric thresholds for calculated risks.

Use sensible defaults but do not bake ISP policy permanently into the controller.

---

# 18. Automatic Map Positioning

Do not hard-code Norway or a specific city.

When the widget loads:

1. Determine visible devices.
2. Calculate their geographic bounds.
3. Fit the map to those bounds.

If only one device exists, use a sensible zoom level.

---

# 19. Dark Mode

The widget must work correctly in both:

* LibreNMS light theme
* LibreNMS dark theme

Pay attention to:

* popup backgrounds
* table text
* map control styling
* warning panels
* borders
* tooltips
* loading states
* error messages

Do not create unreadable white-on-white or black-on-black components.

---

# 20. Weather Provider Failure

External APIs will occasionally fail.

The widget must degrade gracefully.

Do NOT display an empty widget or throw a Laravel exception to the user.

Example:

**Weather data temporarily unavailable**

Last successful update:
09:42

Cached weather data:
8 minutes old

LibreNMS device data should still load.

If usable cached weather exists, display it and identify it as cached/stale if necessary.

---

# 21. Rate Limiting

Respect the selected weather provider's terms, User-Agent requirements, rate limits, attribution requirements, and caching rules.

If MET Norway is used, ensure requests comply with MET Norway's API requirements.

Never hammer the API from browser clients.

Prefer server-side requests from LibreNMS.

---

# 22. Security

Do not expose:

* API secrets
* internal DB credentials
* LibreNMS tokens
* environment secrets

to JavaScript/browser clients.

External weather API calls should normally occur server-side.

Validate all widget settings.

Escape output appropriately.

Do not create SQL injection or XSS opportunities.

Use LibreNMS/Laravel facilities rather than unsafe raw SQL where practical.

---

# 23. Performance

Assume:

* 100 devices initially
* potentially 1,000+
* potentially several thousand interfaces
* many devices may share the same physical site

The widget should remain responsive.

Use:

* caching
* geographic grouping
* bulk LibreNMS queries
* warning polygon matching
* marker clustering

Avoid N+1 database queries.

Avoid N+1 HTTP requests.

---

# 24. Suggested Architecture

Use a clean architecture similar to:

`WeatherRiskController`

Responsible for:

* Widget configuration
* Device retrieval
* response preparation

`WeatherProviderInterface`

Methods similar to:

* getForecast(...)
* getWarnings(...)
* getWeatherRisk(...)

Provider implementation, for example:

`MetNorwayWeatherProvider`

Additional services:

`WeatherRiskService`

Responsible for:

* calculated thresholds
* official warning severity
* risk scoring
* hazard classification

`WeatherGeoService`

Responsible for:

* coordinate grouping
* polygon matching
* distance calculations
* geographic cache keys

`WeatherCacheService`

Responsible for:

* external weather caching
* stale-data fallback

Do not over-engineer if the LibreNMS custom widget architecture makes a simpler implementation preferable, but keep API/weather logic separate from presentation code.

---

# 25. Future Expansion

Structure the implementation so these can be added later without rewriting everything:

### Lightning Strike Overlay

Actual recent lightning strikes:

• • ⚡ • •

Display strikes from e.g. the last:

* 15 min
* 30 min
* 60 min

### Weather Radar

Optional precipitation radar overlay.

### Historical Correlation

Compare:

weather event

versus

LibreNMS:

* interface flaps
* device outages
* optical degradation
* packet loss

Example:

`8 network incidents occurred during this storm.`

### Predictive Risk

Estimate which infrastructure is most likely to suffer an outage based on previous weather incidents.

Do NOT implement machine learning now.

Just avoid architectural decisions that would make these features difficult later.

---

# 26. UX Example

The finished widget could conceptually look like:

---

WEATHER RISK — NEXT 24H

🔴 Severe: 3
🟠 High: 12
🟡 Moderate: 34
🟢 Normal: 281

Warnings: 5

[ Wind ✓ ] [ Lightning ✓ ] [ Rain ✓ ] [ Snow ✓ ]

---

```
            INTERACTIVE MAP

         🟢       🟡

                 🟠

      █████ WARNING AREA █████

                 🔴
```

---

INFRASTRUCTURE AT RISK

🔴 tower-hgs01
Strong wind
Gusts: 31 m/s
Expected: 15:00–19:00

🟠 pop-etne01
Heavy rainfall
Expected: 18:00–23:00

🟡 radio-bokn01
Thunderstorm risk
Expected: 16:00–18:00

---

---

# 27. Files

After inspecting the actual LibreNMS installation, determine exactly which files need to be created or modified.

Do not assume paths blindly.

Before modification, list:

1. Files that will be created.
2. Files that will be modified.
3. Why each change is required.

Then implement the feature.

Where possible, keep the widget isolated from LibreNMS core.

---

# 28. Installation

Provide all required installation steps.

Examples might include:

* PHP dependency installation
* JavaScript dependency installation
* Leaflet installation
* cache clearing
* route clearing
* view clearing
* LibreNMS service reloads
* permissions

Only provide commands that are actually necessary for the implementation.

---

# 29. Testing

Test at minimum:

### Coordinates

* device with valid coordinates
* device without coordinates
* invalid coordinates
* multiple devices with identical coordinates

### Weather

* no warnings
* yellow warning
* orange warning
* red warning
* multiple simultaneous warnings

### LibreNMS

* device UP
* device DOWN
* device groups
* hundreds of devices

### API

* API success
* timeout
* malformed response
* rate-limit response
* cached fallback

### UI

* light mode
* dark mode
* desktop NOC display
* smaller browser
* marker clustering
* warning polygon rendering

---

# 30. Debugging

Add useful Laravel logging for errors involving:

* weather API
* malformed coordinates
* warning polygon parsing
* cache
* unexpected provider responses

Do not flood logs during normal operation.

---

# 31. Deliverables

At completion provide:

1. Architecture summary.
2. Weather API(s) selected and why.
3. Exact files created.
4. Exact files modified.
5. Complete source code.
6. Database changes, if any.
7. Installation commands.
8. LibreNMS cache/reload commands.
9. Configuration instructions.
10. Testing procedure.
11. Troubleshooting procedure.
12. Known limitations.
13. Suggested future improvements.

---

# 32. Important Development Rules

* Inspect the actual LibreNMS installation before assuming implementation details.
* Do not overwrite unrelated existing customizations.
* Do not unnecessarily edit LibreNMS core.
* Do not remove existing widgets.
* Follow existing coding conventions.
* Keep PHP compatible with the installed LibreNMS/PHP version.
* Reuse LibreNMS models/helpers where appropriate.
* Make code production-quality rather than a prototype.
* Run syntax checks on every modified PHP file.
* Run relevant LibreNMS validation/tests.
* Check Laravel logs after implementation.
* Make backups before changing existing files.
* Never fabricate weather data.
* Never make one external API call for every device on every refresh.
* Weather API failures must never break the LibreNMS dashboard.

The final result should be a genuinely useful **ISP NOC weather-risk dashboard**, allowing operators to see at a glance which routers, switches, POPs, radio sites, towers, cabinets, and other infrastructure are currently or soon likely to be exposed to hazardous weather.
