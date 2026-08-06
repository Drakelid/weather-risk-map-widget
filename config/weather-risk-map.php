<?php

/**
 * Weather Risk Map - installation-wide defaults.
 *
 * Per-widget settings (device groups, time window, hazard toggles, thresholds)
 * are edited in the widget's own settings panel and stored per user. This file
 * holds the values that are properties of the *installation* rather than of one
 * dashboard: provider selection, credentials-free API identification, cache
 * TTLs and ISP sensitivity policy.
 *
 * Publish it with:
 *   php artisan vendor:publish --tag=weather-risk-map-config
 */
return [

    /*
    |---------------------------------------------------------------------
    | API identification
    |---------------------------------------------------------------------
    | MET Norway's terms of service REQUIRE a User-Agent naming the
    | application and a way to contact the operator. Requests without one
    | are answered with 403. Set a real contact email before going live.
    |
    | This is an identification string, not a secret, and is only ever sent
    | from the LibreNMS server - never exposed to browsers.
    */
    'user_agent' => env(
        'WEATHER_RISK_MAP_USER_AGENT',
        'LibreNMS-WeatherRiskMap/1.0 (+https://www.librenms.org; set WEATHER_RISK_MAP_USER_AGENT)'
    ),

    /*
    |---------------------------------------------------------------------
    | Providers
    |---------------------------------------------------------------------
    | `forecast` and `warnings` may name different providers. Only MET
    | Norway publishes official warnings, so `warnings` should stay
    | met-norway for Norwegian installations. Set it to null to disable
    | official warnings entirely.
    |
    | Available: met-norway, open-meteo
    */
    'providers' => [
        'forecast' => env('WEATHER_RISK_MAP_FORECAST_PROVIDER', 'met-norway'),
        'warnings' => env('WEATHER_RISK_MAP_WARNING_PROVIDER', 'met-norway'),
    ],

    /*
    |---------------------------------------------------------------------
    | Language for official warning text
    |---------------------------------------------------------------------
    | MetAlerts supports 'no' and 'en'.
    */
    'language' => env('WEATHER_RISK_MAP_LANGUAGE', 'en'),

    /*
    |---------------------------------------------------------------------
    | Caching
    |---------------------------------------------------------------------
    | forecast_ttl / warnings_ttl are the normal serving lifetimes.
    |
    | stale_ttl_minutes is how long a copy is retained purely as a failure
    | fallback. It should be comfortably longer than any outage you expect
    | to ride out; the widget labels such data as cached and states its age.
    |
    | failure_backoff_seconds stops a hard-down provider being retried by
    | every dashboard refresh from every logged-in operator.
    */
    'cache' => [
        'forecast_ttl' => (int) env('WEATHER_RISK_MAP_FORECAST_TTL', 900),   // 15 min
        'warnings_ttl' => (int) env('WEATHER_RISK_MAP_WARNINGS_TTL', 300),   // 5 min
        'stale_ttl_minutes' => (int) env('WEATHER_RISK_MAP_STALE_TTL', 360), // 6 h
        'failure_backoff_seconds' => (int) env('WEATHER_RISK_MAP_BACKOFF', 60),
    ],

    /*
    |---------------------------------------------------------------------
    | HTTP
    |---------------------------------------------------------------------
    | Kept short: the data endpoint is called from the dashboard, and a slow
    | provider must not hold a PHP worker open.
    */
    'http' => [
        'timeout' => (int) env('WEATHER_RISK_MAP_TIMEOUT', 8),
    ],

    /*
    |---------------------------------------------------------------------
    | Request budget
    |---------------------------------------------------------------------
    | Hard ceiling on upstream forecast requests per refresh. Devices are
    | grouped by location and then by geographic cell before this applies,
    | so it is generous in practice; it exists so a pathological inventory
    | (thousands of sites, tiny radius) cannot flood the provider.
    |
    | Cells beyond the budget reuse cached data rather than being dropped.
    */
    'max_cells_per_refresh' => (int) env('WEATHER_RISK_MAP_MAX_CELLS', 80),

    /*
    |---------------------------------------------------------------------
    | Risk thresholds (defaults)
    |---------------------------------------------------------------------
    | Starting points only - these are ISP policy, not physics, and each
    | widget can override them in its settings panel.
    |
    | Ascending hazards trigger at >= the value; `cold` triggers at <=.
    | Omit a level to disable it.
    |
    | Wind figures are m/s. The defaults follow the Beaufort-derived bands
    | in the specification: 12 / 18 / 25 m/s sustained. Gusts are scored on
    | their own higher scale because gust damage is a different mechanism
    | from sustained loading.
    */
    'thresholds' => [
        'wind' => ['moderate' => 12.0, 'high' => 18.0, 'severe' => 25.0],
        'wind_gust' => ['moderate' => 17.0, 'high' => 24.0, 'severe' => 32.0],

        // mm in a single hour
        'rain_hourly' => ['moderate' => 4.0, 'high' => 8.0, 'severe' => 15.0],
        // mm accumulated across the whole selected window
        'rain_accumulated' => ['moderate' => 20.0, 'high' => 40.0, 'severe' => 70.0],

        // % probability of thunder, when the provider supplies it
        'thunder_probability' => ['moderate' => 15.0, 'high' => 40.0, 'severe' => 70.0],
        // Level assigned when only a "...andthunder" symbol is available
        'thunder_symbol_level' => 3,

        // mm water equivalent of snow across the window
        'snow_accumulated' => ['moderate' => 5.0, 'high' => 12.0, 'severe' => 25.0],

        // Freezing-rain / icing band, degrees C
        'icing_temperature_band' => ['min' => -6.0, 'max' => 1.0],
        'icing_min_precipitation' => 0.1,

        'heat' => ['moderate' => 30.0, 'high' => 35.0, 'severe' => 40.0],
        'cold' => ['moderate' => -10.0, 'high' => -20.0, 'severe' => -30.0],
    ],

    /*
    |---------------------------------------------------------------------
    | Infrastructure sensitivity
    |---------------------------------------------------------------------
    | Shifts individual hazards up or down for devices in given LibreNMS
    | device groups. Values are offsets on the 0-5 risk scale.
    |
    | `groups` takes numeric LibreNMS device_group ids. Find them under
    | Devices -> Device Groups, or:
    |     ./lnms tinker --execute="App\Models\DeviceGroup::pluck('name','id')->dump();"
    |
    | Profiles ship empty so nothing changes until an operator opts in.
    | The examples below match the categories in the specification.
    */
    'sensitivity' => [
        'enabled' => true,

        'profiles' => [
            // 'tower' => [
            //     'label'   => 'Radio / tower sites',
            //     'groups'  => [],
            //     'hazards' => ['wind' => 1, 'lightning' => 1, 'icing' => 1],
            // ],
            // 'cabinet' => [
            //     'label'   => 'Outdoor cabinets',
            //     'groups'  => [],
            //     'hazards' => ['heat' => 1, 'cold' => 1, 'flood' => 1, 'rain' => 1],
            // ],
            // 'datacentre' => [
            //     'label'   => 'Core datacentres',
            //     'groups'  => [],
            //     'hazards' => ['wind' => -1, 'rain' => -1],
            // ],
            // 'mountain' => [
            //     'label'   => 'Mountain sites',
            //     'groups'  => [],
            //     'hazards' => ['snow' => 1, 'icing' => 1, 'wind' => 1],
            // ],
        ],
    ],

    /*
    |---------------------------------------------------------------------
    | Widget defaults
    |---------------------------------------------------------------------
    | Applied to newly added widgets.
    */
    'defaults' => [
        'refresh' => 600,          // seconds; 10 min
        'risk_period' => 24,       // hours
        'min_severity' => 0,       // 0 = show all
        'weather_radius_km' => 10,
        'clustering' => true,
        'show_warnings' => true,
        'map_mode' => 'auto',      // auto | remember
    ],
];
