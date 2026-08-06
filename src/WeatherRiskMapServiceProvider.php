<?php

namespace LibreNMS\Plugins\WeatherRiskMap;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use LibreNMS\Plugins\WeatherRiskMap\Console\DiagnoseCommand;
use LibreNMS\Plugins\WeatherRiskMap\Console\WarmCacheCommand;
use LibreNMS\Plugins\WeatherRiskMap\Services\DeviceLocationService;
use LibreNMS\Plugins\WeatherRiskMap\Services\WeatherRiskMapFactory;
use Psr\Log\LoggerInterface;

/**
 * Registers the Weather Risk Map widget.
 *
 * Discovered automatically through Composer's Laravel package-discovery
 * (`extra.laravel.providers` in composer.json), the same mechanism the other
 * LibreNMS package plugins on this installation use.
 *
 * Nothing here modifies or overrides a LibreNMS core file: the widget is added
 * purely by registering a route under the `ajax/dash` prefix, which is how
 * DashboardController::listWidgets() enumerates available widgets.
 */
class WeatherRiskMapServiceProvider extends ServiceProvider
{
    public const PLUGIN_NAME = 'weather-risk-map';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/weather-risk-map.php', 'weather-risk-map');

        // A dedicated log channel keeps weather-provider noise out of the main
        // LibreNMS log while staying easy to find during troubleshooting.
        // Only defined if the administrator has not already defined one.
        if (config('logging.channels.weather-risk-map') === null) {
            config([
                'logging.channels.weather-risk-map' => [
                    'driver' => 'single',
                    'path' => storage_path('logs/weather-risk-map.log'),
                    'level' => env('WEATHER_RISK_MAP_LOG_LEVEL', 'info'),
                    'replace_placeholders' => true,
                ],
            ]);
        }

        $this->app->singleton(DeviceLocationService::class, fn ($app) => new DeviceLocationService($this->logger()));

        $this->app->singleton(WeatherRiskMapFactory::class, fn ($app) => new WeatherRiskMapFactory(
            http: $app->make(HttpFactory::class),
            cache: $app->make(CacheFactory::class),
            logger: $this->logger(),
            devices: $app->make(DeviceLocationService::class),
        ));
    }

    public function boot(): void
    {
        // Routes are registered unconditionally: they carry their own 'auth'
        // middleware, and the widget must be discoverable by listWidgets()
        // on every request that renders a dashboard.
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadTranslationsFrom(__DIR__ . '/../lang', 'weather-risk-map');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'weather-risk-map');

        /*
         * Register the package view directory as an ordinary view location as
         * well as a namespace.
         *
         * This is needed because core decides whether a widget is currently
         * showing its settings panel by inspecting the VIEW NAME:
         *
         *     $show_settings = Str::startsWith($view->getName(), 'widgets.settings.');
         *
         * A namespaced name ("weather-risk-map::settings") fails that test, and
         * the panel would silently drop out of edit mode on the next auto
         * refresh, discarding unsaved changes. Serving the views as
         * `widgets.weather-risk-map` and `widgets.settings.weather-risk-map`
         * makes the widget behave exactly like a built-in one.
         *
         * Core's own view paths are registered first and therefore still win
         * for any identical name, so this cannot shadow a LibreNMS view.
         */
        View::addLocation(__DIR__ . '/../resources/views');

        $this->publishes(
            [__DIR__ . '/../config/weather-risk-map.php' => config_path('weather-risk-map.php')],
            'weather-risk-map-config'
        );

        if ($this->app->runningInConsole()) {
            $this->commands([DiagnoseCommand::class, WarmCacheCommand::class]);
        }
    }

    /**
     * Resolve the package log channel, falling back to the default logger if
     * the channel cannot be built (for example under a bare test container).
     */
    private function logger(): LoggerInterface
    {
        try {
            return Log::channel('weather-risk-map');
        } catch (\Throwable) {
            return Log::getFacadeRoot();
        }
    }
}
