{{--
    Widget settings panel.

    Extends LibreNMS's own widgets.settings.base so the form posts through
    core's widget_settings() handler and inherits the standard refresh field
    and Save button. Only the @section('form') body is ours.

    Inputs use Bootstrap form-control classes to match every other LibreNMS
    settings panel in both themes; no custom colours are introduced here, so
    dark mode is inherited rather than reimplemented.
--}}
@extends('widgets.settings.base')

@section('form')

    {{-- ------------------------------------------------------------ General --}}
    <h5 class="tw:font-bold tw:mt-1 tw:mb-2">{{ __('weather-risk-map::widget.settings.general') }}</h5>

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
        <p class="help-block tw:text-xs">{{ __('weather-risk-map::widget.settings.device_groups_help') }}</p>
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
    <h5 class="tw:font-bold tw:mt-3 tw:mb-2">{{ __('weather-risk-map::widget.settings.map') }}</h5>

    <div class="form-group">
        <label for="map_mode-{{ $id }}" class="control-label">
            {{ __('weather-risk-map::widget.settings.map_mode') }}
        </label>
        <select class="form-control" name="map_mode" id="map_mode-{{ $id }}">
            <option value="auto" @selected($settings->mapMode === 'auto')>{{ __('weather-risk-map::widget.settings.map_mode_auto') }}</option>
            <option value="remember" @selected($settings->mapMode === 'remember')>{{ __('weather-risk-map::widget.settings.map_mode_remember') }}</option>
        </select>
    </div>

    <div class="form-group">
        <label for="weather_radius_km-{{ $id }}" class="control-label">
            {{ __('weather-risk-map::widget.settings.radius') }}
        </label>
        <select class="form-control" name="weather_radius_km" id="weather_radius_km-{{ $id }}">
            @foreach ($radii as $radius)
                <option value="{{ $radius }}" @selected($settings->weatherRadiusKm === $radius)>{{ $radius }} km</option>
            @endforeach
        </select>
        <p class="help-block tw:text-xs">{{ __('weather-risk-map::widget.settings.radius_help') }}</p>
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
    <h5 class="tw:font-bold tw:mt-3 tw:mb-2">{{ __('weather-risk-map::widget.settings.hazards') }}</h5>

    <div class="tw:grid tw:grid-cols-2 tw:gap-x-4">
        @foreach ($hazards as $hazard)
            <div class="checkbox tw:my-0">
                <label>
                    {{-- Flat names: core's settings serializer does not build
                         nested arrays. WidgetSettings expands these back. --}}
                    <input type="hidden" name="hazard_{{ $hazard->value }}" value="0">
                    <input type="checkbox" name="hazard_{{ $hazard->value }}" value="1"
                           @checked($settings->hazards[$hazard->value] ?? true)>
                    {{ $hazard->icon() }} {{ $hazard->label() }}
                </label>
            </div>
        @endforeach
    </div>

    {{-- --------------------------------------------------------- Thresholds --}}
    <h5 class="tw:font-bold tw:mt-3 tw:mb-2">{{ __('weather-risk-map::widget.settings.thresholds') }}</h5>
    <p class="help-block tw:text-xs">{{ __('weather-risk-map::widget.settings.thresholds_help') }}</p>

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

    <div class="tw:overflow-x-auto">
        <table class="table table-condensed tw:text-xs">
            <thead>
                <tr>
                    <th></th>
                    <th class="tw:text-center">🟡 {{ __('weather-risk-map::widget.levels.moderate') }}</th>
                    <th class="tw:text-center">🟠 {{ __('weather-risk-map::widget.levels.high') }}</th>
                    <th class="tw:text-center">🔴 {{ __('weather-risk-map::widget.levels.severe') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($editable as $key => $label)
                    <tr>
                        <td class="tw:whitespace-nowrap">{{ $label }}</td>
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
    <script type="text/javascript">
        // Reuse LibreNMS's own device-group select2 source so groups are
        // resolved by id through the core endpoint, never by name matching.
        init_select2('#device_groups-{{ $id }}', 'device-group', { multiple: true });
    </script>
@endsection
