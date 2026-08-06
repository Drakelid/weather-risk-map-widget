<?php

use Illuminate\Support\Facades\Route;
use LibreNMS\Plugins\WeatherRiskMap\Http\Controllers\WeatherRiskDataController;
use LibreNMS\Plugins\WeatherRiskMap\Http\Controllers\WeatherRiskMapWidgetController;

/*
 * Widget registration.
 *
 * LibreNMS discovers dashboard widgets by scanning the route table for routes
 * whose prefix is exactly 'ajax/dash' (App\Http\Controllers\DashboardController::listWidgets).
 * Registering here therefore adds "Weather Risk Map" to the Add Widget menu
 * without modifying a single LibreNMS core file, which is what keeps the
 * feature safe across LibreNMS upgrades.
 *
 * The prefix and middleware mirror core's own widget group in
 * librenms/routes/web.php: web session handling plus 'auth'.
 */
Route::middleware(['web', 'auth'])->group(function (): void {
    Route::prefix('ajax/dash')->group(function (): void {
        Route::post('weather-risk-map', WeatherRiskMapWidgetController::class)
            ->name('widget.weather-risk-map');
    });

    /*
     * Asynchronous data endpoint.
     *
     * Deliberately OUTSIDE the ajax/dash prefix: anything under that prefix is
     * treated as a widget by listWidgets() and would appear as a bogus second
     * entry in the Add Widget menu.
     *
     * Throttled because it is the only route here that can trigger outbound
     * requests. Caching means a burst mostly hits warm data, but the limit
     * bounds the worst case regardless of how many NOC screens are open.
     */
    Route::post('plugin/weather-risk-map/data', WeatherRiskDataController::class)
        ->middleware('throttle:60,1')
        ->name('weather-risk-map.data');
});
