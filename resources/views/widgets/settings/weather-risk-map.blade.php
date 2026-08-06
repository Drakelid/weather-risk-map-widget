{{--
    Widget settings panel.

    Extends LibreNMS's own widgets.settings.base so the form posts through
    core's widget_settings() handler and inherits the standard refresh field
    and Save button. Only the @section('form') body is ours.

    Inputs use Bootstrap form-control classes to match every other LibreNMS
    settings panel in both themes, so dark mode is inherited rather than
    reimplemented.

    No Tailwind: LibreNMS compiles it from its own tree only, and this package
    sits outside that tree behind a vendor symlink, so `tw:` utilities used here
    would never be generated. The few layout rules needed are in the scoped
    style block at the end of this file.
--}}
@extends('widgets.settings.base')

@section('form')

    {{-- ------------------------------------------------------------ General --}}
    <h5 class="wxset-h">{{ __('weather-risk-map::widget.settings.general') }}</h5>

    <div class="form-group">
        <label for="title-{{ $id }}" class="control-label">{{ __('weather-risk-map::widget.settings.title') }}</label>
        <input type="text" class="form-control" name="title" id="title-{{ $id }}"
               maxlength="120"
               placeholder="{{ __('weather-risk-map::widget.settings.title_placeholder') }}"
               value="{{ $settings->title }}">
    </div>

    <div class="form-group">
        <label for="device_groups-{{ $id }}" class="control-label">
            {{ __('weather-risk-map::widget.settings.device_groups') }}
        </label>
        {{-- Multiple selection: the name carries [] so Laravel receives an array. --}}
        <select class="form-control" name="device_groups[]" id="device_groups-{{ $id }}"
                multiple data-placeholder="{{ __('weather-risk-map::widget.settings.device_groups_help') }}">
            @foreach ($selectedGroups as $groupId => $groupName)
                <option value="{{ $groupId }}" selected>{{ $groupName }}</option>
            @endforeach
        </select>
        <p class="help-block wxset-help">{{ __('weather-risk-map::widget.settings.device_groups_help') }}</p>
    </div>

    <div class="form-group">
        <label for="risk_period-{{ $id }}" class="control-label">
            {{ __('weather-risk-map::widget.settings.risk_period') }}
        </label>
        <select class="form-control" name="risk_period" id="risk_period-{{ $id }}">
            @foreach ($periods as $period)
                <option value="{{ $period }}" @selected($settings->riskPeriod === $period)>
                    {{ __('weather-risk-map::widget.window.' . $period) }}
                </option>
            @endforeach
        </select>
    </div>

    <div class="form-group">
        <label for="min_severity-{{ $id }}" class="control-label">
            {{ __('weather-risk-map::widget.settings.min_severity') }}
        </label>
        <select class="form-control" name="min_severity" id="min_severity-{{ $id }}">
            <option value="0" @selected($settings->minSeverity === 0)>{{ __('weather-risk-map::widget.settings.all_severities') }}</option>
            <option value="2" @selected($settings->minSeverity === 2)>{{ __('weather-risk-map::widget.settings.moderate_plus') }}</option>
            <option value="3" @selected($settings->minSeverity === 3)>{{ __('weather-risk-map::widget.settings.high_plus') }}</option>
            <option value="4" @selected($settings->minSeverity === 4)>{{ __('weather-risk-map::widget.settings.severe_only') }}</option>
        </select>
    </div>

    {{-- ---------------------------------------------------------------- Map --}}
    <h5 class="wxset-h wxset-h-gap">{{ __('weather-risk-map::widget.settings.map') }}</h5>

    <div class="form-group">
        <label for="map_mode-{{ $id }}" class="control-label">
            {{ __('weather-risk-map::widget.settings.map_mode') }}
        </label>
        <select class="form-control" name="map_mode" id="map_mode-{{ $id }}">
            <option value="auto" @selected($settings->mapMode === 'auto')>{{ __('weather-risk-map::widget.settings.map_mode_auto') }}</option>
            <option value="fixed" @selected($settings->mapMode === 'fixed')>{{ __('weather-risk-map::widget.settings.map_mode_fixed') }}</option>
            <option value="remember" @selected($settings->mapMode === 'remember')>{{ __('weather-risk-map::widget.settings.map_mode_remember') }}</option>
        </select>
        <p class="help-block wxset-help">{{ __('weather-risk-map::widget.settings.map_mode_help') }}</p>
    </div>

    {{--
        Starting position. Only applied in "Fixed position" mode, and only when
        BOTH coordinates are given - a half-set position would drop the operator
        somewhere they never chose, so the view falls back to auto-fit instead.
    --}}
    <div class="wxset-position">
        <div class="form-group">
            <label for="init_lat-{{ $id }}" class="control-label">
                {{ __('weather-risk-map::widget.settings.init_lat') }}
            </label>
            <input type="number" step="any" min="-90" max="90" class="form-control"
                   name="init_lat" id="init_lat-{{ $id }}"
                   value="{{ $settings->initLat }}"
                   placeholder="{{ $suggestedLat ?? __('weather-risk-map::widget.settings.init_lat_placeholder') }}">
        </div>

        <div class="form-group">
            <label for="init_lng-{{ $id }}" class="control-label">
                {{ __('weather-risk-map::widget.settings.init_lng') }}
            </label>
            <input type="number" step="any" min="-180" max="180" class="form-control"
                   name="init_lng" id="init_lng-{{ $id }}"
                   value="{{ $settings->initLng }}"
                   placeholder="{{ $suggestedLng ?? __('weather-risk-map::widget.settings.init_lng_placeholder') }}">
        </div>

        <div class="form-group">
            <label for="init_zoom-{{ $id }}" class="control-label">
                {{ __('weather-risk-map::widget.settings.init_zoom') }}
            </label>
            <input type="number" step="0.1" min="{{ $minZoom }}" max="{{ $maxZoom }}" class="form-control"
                   name="init_zoom" id="init_zoom-{{ $id }}"
                   value="{{ $settings->initZoom }}"
                   placeholder="{{ $suggestedZoom ?? 5 }}">
        </div>
    </div>
    <p class="help-block wxset-help">{{ __('weather-risk-map::widget.settings.init_help') }}</p>

    <div class="form-group">
        <label for="weather_radius_km-{{ $id }}" class="control-label">
            {{ __('weather-risk-map::widget.settings.radius') }}
        </label>
        <select class="form-control" name="weather_radius_km" id="weather_radius_km-{{ $id }}">
            @foreach ($radii as $radius)
                <option value="{{ $radius }}" @selected($settings->weatherRadiusKm === $radius)>{{ $radius }} km</option>
            @endforeach
        </select>
        <p class="help-block wxset-help">{{ __('weather-risk-map::widget.settings.radius_help') }}</p>
    </div>

    <div class="checkbox">
        <label>
            {{-- Hidden companion so an unchecked box still posts a value. --}}
            <input type="hidden" name="clustering" value="0">
            <input type="checkbox" name="clustering" value="1" @checked($settings->clustering)>
            {{ __('weather-risk-map::widget.settings.clustering') }}
        </label>
    </div>

    <div class="checkbox">
        <label>
            <input type="hidden" name="show_warnings" value="0">
            <input type="checkbox" name="show_warnings" value="1" @checked($settings->showWarnings)>
            {{ __('weather-risk-map::widget.settings.show_warnings') }}
        </label>
    </div>

    {{-- ------------------------------------------------------------ Hazards --}}
    <h5 class="wxset-h wxset-h-gap">{{ __('weather-risk-map::widget.settings.hazards') }}</h5>

    <div class="wxset-grid">
        @foreach ($hazards as $hazard)
            <div class="checkbox wxset-check">
                <label>
                    {{-- Flat names: core's settings serializer does not build
                         nested arrays. WidgetSettings expands these back. --}}
                    <input type="hidden" name="hazard_{{ $hazard->value }}" value="0">
                    <input type="checkbox" name="hazard_{{ $hazard->value }}" value="1"
                           @checked($settings->hazards[$hazard->value] ?? true)>
                    {{-- No emoji here: several hazard emoji default to text
                         presentation and render as tofu. The label is enough. --}}
                    {{ $hazard->label() }}
                </label>
            </div>
        @endforeach
    </div>

    {{-- --------------------------------------------------------- Thresholds --}}
    <h5 class="wxset-h wxset-h-gap">{{ __('weather-risk-map::widget.settings.thresholds') }}</h5>
    <p class="help-block wxset-help">{{ __('weather-risk-map::widget.settings.thresholds_help') }}</p>

    @php
        // Only the numeric, three-level threshold groups are editable here.
        // Structural values (icing band, symbol level) stay in the config file
        // where they can be documented properly.
        $editable = [
            'wind' => 'Wind (m/s)',
            'wind_gust' => 'Wind gusts (m/s)',
            'rain_hourly' => 'Rain (mm/h)',
            'rain_accumulated' => 'Rain total (mm)',
            'snow_accumulated' => 'Snow (mm water eq.)',
            'thunder_probability' => 'Thunder probability (%)',
            'heat' => 'Heat (°C, at or above)',
            'cold' => 'Cold (°C, at or below)',
        ];
    @endphp

    <div class="wxset-scroll">
        <table class="table table-condensed wxset-table">
            <thead>
                <tr>
                    <th></th>
                    <th class="text-center"><span class="wxrisk-thdot" style="background:#a8760a"></span> {{ __('weather-risk-map::widget.levels.moderate') }}</th>
                    <th class="text-center"><span class="wxrisk-thdot" style="background:#c9560d"></span> {{ __('weather-risk-map::widget.levels.high') }}</th>
                    <th class="text-center"><span class="wxrisk-thdot" style="background:#c62222"></span> {{ __('weather-risk-map::widget.levels.severe') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($editable as $key => $label)
                    <tr>
                        <td class="wxset-nowrap">{{ $label }}</td>
                        @foreach (['moderate', 'high', 'severe'] as $level)
                            <td>
                                {{-- Flat name for the same reason as the hazard
                                     toggles; split on the last underscore. --}}
                                <input type="number" step="any" class="form-control input-sm"
                                       name="threshold_{{ $key }}_{{ $level }}"
                                       id="threshold-{{ $key }}-{{ $level }}-{{ $id }}"
                                       value="{{ $settings->thresholds[$key][$level] ?? '' }}"
                                       placeholder="{{ $thresholdDefaults[$key][$level] ?? '' }}">
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection

@section('javascript')
    <style>
        /* Scoped to this settings panel; inherits Bootstrap/LibreNMS theming. */
        .dashboard-widget-settings .wxset-h { font-size: 14px; font-weight: 700; margin: 4px 0 8px; }
        .dashboard-widget-settings .wxset-h-gap { margin-top: 16px; }
        .dashboard-widget-settings .wxset-help { font-size: 12px; }
        .dashboard-widget-settings .wxset-grid {
            display: grid; grid-template-columns: 1fr 1fr; column-gap: 16px;
        }
        .dashboard-widget-settings .wxset-check { margin-top: 0; margin-bottom: 0; }
        .dashboard-widget-settings .wxset-scroll { overflow-x: auto; }
        .dashboard-widget-settings .wxset-table { font-size: 12.5px; }
        .dashboard-widget-settings .wxset-nowrap { white-space: nowrap; }
        /* Three position fields side by side; they are meaningless apart. */
        .dashboard-widget-settings .wxset-position {
            display: grid; grid-template-columns: 1fr 1fr 1fr; column-gap: 10px;
        }
        .dashboard-widget-settings .wxset-position .form-group { margin-bottom: 6px; }
        /* Themed dot instead of a coloured-circle emoji, which is Emoji 12.0
           and renders as tofu wherever the font lacks it. */
        .dashboard-widget-settings .wxrisk-thdot {
            display: inline-block; width: 10px; height: 10px;
            border-radius: 50%; vertical-align: -1px;
        }
    </style>
    <script type="text/javascript">
        // Reuse LibreNMS's own device-group select2 source so groups are
        // resolved by id through the core endpoint, never by name matching.
        init_select2('#device_groups-{{ $id }}', 'device-group', { multiple: true });
    </script>
@endsection
