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
    /*
     * The two nested prefix() calls are REQUIRED. Do not collapse them into a
     * single ->prefix('ajax/dash').
     *
     * listWidgets() compares with an exact string match:
     *
     *     $route->getPrefix() === 'ajax/dash'
     *
     * Laravel builds that string in RouteGroup::formatPrefix() as
     * `trim($old, '/') . '/' . trim($new, '/')`. With one prefix level there is
     * no parent prefix, so $old is '' and the result is "/ajax/dash" - with a
     * leading slash, which fails the comparison and silently removes the widget
     * from the Add Widget menu. Nesting a second level makes trim() strip that
     * slash, producing "ajax/dash" exactly as core's own widget group does.
     *
     * The route URI is trimmed separately, so `route:list` looks correct either
     * way. Only the prefix differs, which makes this failure easy to misread.
     */
    Route::prefix('ajax')->group(function (): void {
        Route::prefix('dash')->group(function (): void {
            Route::post('weather-risk-map', WeatherRiskMapWidgetController::class)
                ->name('widget.weather-risk-map');
        });
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
