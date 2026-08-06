{{--
    Weather Risk Map widget shell.

    Renders immediately with no weather data: everything is fetched
    asynchronously from the data endpoint so a slow or dead weather provider
    cannot delay the LibreNMS dashboard.

    Dark mode: LibreNMS uses Tailwind v4 with the `tw:` prefix and a `dark:`
    variant. Colours below are always declared as a light/dark pair. Leaflet's
    own popup and control chrome is styled explicitly further down, because
    Leaflet ships hard-coded white backgrounds that would otherwise render as
    white-on-white in the dark theme.
--}}
@php
    $wid = 'wxrisk-' . $id;
@endphp

<div id="{{ $wid }}" class="wxrisk-widget tw:flex tw:flex-col tw:h-full tw:text-gray-800 dark:tw:text-dark-white-100" data-reload="false">

    {{-- ---------------------------------------------------------- summary --}}
    <div class="wxrisk-summary tw:shrink-0 tw:mb-2">
        <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-2 tw:mb-1">
            <span class="tw:font-bold tw:text-sm tw:tracking-wide" data-role="heading">
                {{ __('weather-risk-map::widget.summary_heading') }}
            </span>
            <span class="tw:text-xs tw:opacity-70" data-role="window-label"></span>
            <span class="wxrisk-badge tw:hidden" data-role="degraded"
                  title="{{ __('weather-risk-map::widget.degraded') }}"></span>
        </div>

        {{-- Counters double as severity filters (spec section 13). --}}
        <div class="tw:flex tw:flex-wrap tw:gap-1" data-role="counters"></div>

        <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-x-3 tw:gap-y-1 tw:mt-1 tw:text-xs">
            <span data-role="warning-count" class="tw:opacity-80"></span>
            <span data-role="highest" class="tw:opacity-90"></span>
        </div>

        {{-- Hazard toggles (spec section 11). --}}
        <div class="tw:flex tw:flex-wrap tw:gap-1 tw:mt-1" data-role="hazard-toggles"></div>
    </div>

    {{-- ------------------------------------------------------------- map --}}
    <div class="wxrisk-map-wrap tw:relative tw:grow tw:min-h-[220px] tw:rounded tw:overflow-hidden
                tw:border tw:border-black/10 dark:tw:border-black/60">
        <div id="{{ $wid }}-map" class="wxrisk-map tw:absolute tw:inset-0"></div>

        <div data-role="overlay"
             class="tw:absolute tw:inset-0 tw:flex tw:items-center tw:justify-center tw:text-sm
                    tw:bg-white/80 dark:tw:bg-[#22262b]/85 tw:z-[500]">
            <span data-role="overlay-text">{{ __('weather-risk-map::widget.loading') }}</span>
        </div>
    </div>

    {{-- ---------------------------------------------- infrastructure table --}}
    <div class="wxrisk-table-wrap tw:shrink-0 tw:mt-2 tw:max-h-[38%] tw:overflow-y-auto
                tw:border tw:border-black/10 dark:tw:border-black/60 tw:rounded">
        <table class="tw:w-full tw:text-xs wxrisk-table">
            <thead class="tw:sticky tw:top-0 tw:bg-gray-200 dark:tw:bg-dark-gray-200">
                <tr>
                    <th data-sort="risk" class="tw:cursor-pointer tw:px-2 tw:py-1 tw:text-left">{{ __('weather-risk-map::widget.table.risk') }}</th>
                    <th data-sort="device" class="tw:cursor-pointer tw:px-2 tw:py-1 tw:text-left">{{ __('weather-risk-map::widget.table.device') }}</th>
                    <th data-sort="location" class="tw:cursor-pointer tw:px-2 tw:py-1 tw:text-left">{{ __('weather-risk-map::widget.table.location') }}</th>
                    <th data-sort="hazard" class="tw:cursor-pointer tw:px-2 tw:py-1 tw:text-left">{{ __('weather-risk-map::widget.table.hazard') }}</th>
                    <th data-sort="peak" class="tw:px-2 tw:py-1 tw:text-left">{{ __('weather-risk-map::widget.table.peak') }}</th>
                    <th data-sort="time" class="tw:cursor-pointer tw:px-2 tw:py-1 tw:text-left">{{ __('weather-risk-map::widget.table.time') }}</th>
                </tr>
            </thead>
            <tbody data-role="rows"></tbody>
        </table>
    </div>

    <div class="tw:shrink-0 tw:mt-1 tw:text-[10px] tw:opacity-60" data-role="attribution"></div>
</div>

<style>
/*
 * Scoped to .wxrisk-widget so nothing here can leak into other widgets.
 * Leaflet's stylesheet hard-codes light colours on popups, tooltips and
 * controls, so each is re-declared for the dark theme.
 */
.wxrisk-widget .wxrisk-chip {
    display: inline-flex; align-items: center; gap: .3em;
    padding: .12em .5em; border-radius: 999px;
    font-size: 11px; line-height: 1.6; cursor: pointer;
    border: 1px solid transparent; user-select: none;
    background: rgba(0,0,0,.06);
}
html.dark .wxrisk-widget .wxrisk-chip { background: rgba(255,255,255,.08); }
.wxrisk-widget .wxrisk-chip.is-active { border-color: currentColor; font-weight: 600; }
.wxrisk-widget .wxrisk-chip.is-off { opacity: .4; text-decoration: line-through; }

.wxrisk-widget .wxrisk-badge {
    display: inline-block; padding: .1em .5em; border-radius: 3px;
    font-size: 10px; font-weight: 600;
    background: #d9a406; color: #1a1a1a;
}

.wxrisk-widget .wxrisk-table th { font-weight: 600; white-space: nowrap; }
.wxrisk-widget .wxrisk-table tbody tr { cursor: pointer; border-top: 1px solid rgba(0,0,0,.08); }
html.dark .wxrisk-widget .wxrisk-table tbody tr { border-top-color: rgba(255,255,255,.10); }
.wxrisk-widget .wxrisk-table tbody tr:hover { background: rgba(0,0,0,.05); }
html.dark .wxrisk-widget .wxrisk-table tbody tr:hover { background: rgba(255,255,255,.07); }
.wxrisk-widget .wxrisk-table td { padding: .2em .5em; vertical-align: top; }

/* Device marker: weather risk is the fill, LibreNMS down state is the ring. */
.wxrisk-marker { border-radius: 50%; border: 2px solid rgba(255,255,255,.85); box-sizing: border-box; }
html.dark .wxrisk-marker { border-color: rgba(255,255,255,.5); }
.wxrisk-marker.is-down { border-color: #111; border-width: 3px; border-style: double; }
.wxrisk-marker.is-disabled { opacity: .45; }

.wxrisk-cluster {
    border-radius: 50%; color: #fff; font-weight: 700; font-size: 12px;
    display: flex; align-items: center; justify-content: center;
    border: 2px solid rgba(255,255,255,.7); box-shadow: 0 0 4px rgba(0,0,0,.4);
}

/* Leaflet chrome in dark mode */
html.dark .wxrisk-map .leaflet-popup-content-wrapper,
html.dark .wxrisk-map .leaflet-popup-tip {
    background: #2b3036; color: #e6e6e6; box-shadow: 0 1px 8px rgba(0,0,0,.6);
}
html.dark .wxrisk-map .leaflet-bar a,
html.dark .wxrisk-map .leaflet-control-layers {
    background: #2b3036; color: #e6e6e6; border-color: #14171a;
}
html.dark .wxrisk-map .leaflet-bar a:hover { background: #3a4048; }
html.dark .wxrisk-map .leaflet-control-attribution {
    background: rgba(20,23,26,.8); color: #b8b8b8;
}
html.dark .wxrisk-map .leaflet-control-attribution a { color: #7fb3e8; }
/* Tiles are bright; a slight dim keeps markers readable without a paid dark basemap. */
html.dark .wxrisk-map .leaflet-tile-pane { filter: brightness(.72) saturate(.75); }

.wxrisk-widget .wxrisk-popup { font-size: 12px; min-width: 210px; max-width: 290px; }
.wxrisk-widget .wxrisk-popup h4 { margin: 0 0 .3em; font-size: 13px; font-weight: 700; }
.wxrisk-widget .wxrisk-popup dl { margin: 0; }
.wxrisk-popup .wxrisk-kv { display: flex; justify-content: space-between; gap: .7em; }
.wxrisk-popup .wxrisk-sec { margin-top: .45em; padding-top: .35em; border-top: 1px solid rgba(128,128,128,.35); }
.wxrisk-popup .wxrisk-muted { opacity: .7; }
.wxrisk-popup .wxrisk-scroll { max-height: 150px; overflow-y: auto; }
</style>

<script type="application/javascript">
(function () {
    'use strict';

    var widgetId   = @json($id);
    var rootId     = @json($wid);
    var mapId      = rootId + '-map';
    var dataUrl    = @json(route('weather-risk-map.data'));
    var csrfToken  = @json(csrf_token());
    var settings   = @json($settingsJson);
    var mapConfig  = @json($mapConfig);
    var lang       = @json(__('weather-risk-map::widget'));
    var deviceBase = @json(url('device'));

    var $root = $('#' + rootId);
    if (! $root.length) { return; }

    // Per-widget state. Kept on the element so multiple Weather Risk Map
    // widgets on one dashboard never share filters or map handles.
    var state = {
        payload: null,
        severityFilter: settings.min_severity || 0,
        hazardFilter: {},        // hazard -> false when switched off client-side
        sort: { key: 'risk', dir: 'desc' },
        markers: {},
        cluster: null,
        polygonLayer: null,
        fitted: false,
        loading: false
    };

    var LEVELS = [
        { key: 'extreme',  value: 5, color: '#7b2ff7', emoji: '🟣' },
        { key: 'severe',   value: 4, color: '#cc2b2b', emoji: '🔴' },
        { key: 'high',     value: 3, color: '#e2711d', emoji: '🟠' },
        { key: 'moderate', value: 2, color: '#d9a406', emoji: '🟡' },
        { key: 'info',     value: 1, color: '#2f7ed8', emoji: '🔵' },
        { key: 'normal',   value: 0, color: '#2e9e4f', emoji: '🟢' }
    ];

    /** Escape untrusted strings before they reach innerHTML. */
    function esc(value) {
        if (value === null || value === undefined) { return ''; }
        return String(value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function t(path, fallback) {
        var node = lang;
        var parts = path.split('.');
        for (var i = 0; i < parts.length; i++) {
            if (node && Object.prototype.hasOwnProperty.call(node, parts[i])) {
                node = node[parts[i]];
            } else {
                return fallback !== undefined ? fallback : path;
            }
        }
        return node;
    }

    function levelMeta(value) {
        for (var i = 0; i < LEVELS.length; i++) {
            if (LEVELS[i].value === value) { return LEVELS[i]; }
        }
        return LEVELS[LEVELS.length - 1];
    }

    function overlay(text) {
        var $o = $root.find('[data-role="overlay"]');
        if (text === null) { $o.addClass('tw:hidden'); return; }
        $o.removeClass('tw:hidden').find('[data-role="overlay-text"]').text(text);
    }

    // ------------------------------------------------------------ rendering

    function renderSummary() {
        var p = state.payload;
        if (! p) { return; }

        var windowLabel = t('window.' + p.window_hours, p.window_hours + 'h');
        $root.find('[data-role="window-label"]').text('— ' + windowLabel);

        var $counters = $root.find('[data-role="counters"]').empty();
        LEVELS.forEach(function (level) {
            var count = p.counts[level.key] || 0;
            // Hide empty extreme/info tiers so the header stays scannable,
            // but always show the four levels the NOC expects.
            if (count === 0 && (level.key === 'extreme' || level.key === 'info')) { return; }

            var active = state.severityFilter === level.value && level.value > 0;
            $('<span/>', {
                'class': 'wxrisk-chip' + (active ? ' is-active' : ''),
                'style': 'color:' + level.color,
                'html': level.emoji + ' <b>' + count + '</b> <span class="tw:opacity-80">'
                        + esc(t('levels.' + level.key, level.key)) + '</span>',
                'data-level': level.value
            }).appendTo($counters);
        });

        var warningCount = (p.warnings || []).length;
        $root.find('[data-role="warning-count"]')
             .text(t('active_warnings', 'Active warnings') + ': ' + warningCount);

        var $highest = $root.find('[data-role="highest"]').empty();
        if (p.highest) {
            var meta = levelMeta(p.highest.risk);
            $highest.html(
                '<b>' + esc(t('highest_risk', 'Highest risk')) + ':</b> '
                + '<span style="color:' + meta.color + '">'
                + esc(p.highest.hazard) + ' — ' + esc(p.highest.location || p.highest.device)
                + (p.highest.time ? ' — ' + esc(p.highest.time) : '')
                + '</span>'
            );
        }

        var $degraded = $root.find('[data-role="degraded"]');
        if (p.meta && (p.meta.degraded || p.meta.stale)) {
            var msg = p.meta.message || t('cached_notice', 'Showing cached weather data');
            $degraded.removeClass('tw:hidden').text(
                p.meta.degraded ? t('degraded', 'Weather data temporarily unavailable') : t('cached_notice')
            ).attr('title', msg);
        } else {
            $degraded.addClass('tw:hidden');
        }

        $root.find('[data-role="attribution"]').text(
            (p.meta && p.meta.attribution ? p.meta.attribution : '')
            + (p.meta && p.meta.cells ? '  ·  ' + p.meta.cells + ' forecast areas / '
               + p.meta.locations + ' sites' : '')
        );
    }

    function renderHazardToggles() {
        var $wrap = $root.find('[data-role="hazard-toggles"]').empty();
        var enabled = (state.payload && state.payload.settings && state.payload.settings.hazards) || settings.hazards || {};

        Object.keys(enabled).forEach(function (hazard) {
            if (! enabled[hazard]) { return; }   // disabled in widget settings
            var on = state.hazardFilter[hazard] !== false;
            $('<span/>', {
                'class': 'wxrisk-chip' + (on ? '' : ' is-off'),
                'data-hazard': hazard,
                'text': hazard.charAt(0).toUpperCase() + hazard.slice(1)
            }).appendTo($wrap);
        });
    }

    /** Devices passing the severity filter and at least one enabled hazard. */
    function visibleDevices() {
        if (! state.payload) { return []; }

        return state.payload.devices.filter(function (device) {
            if (device.risk < state.severityFilter) { return false; }
            if (device.risk === 0) { return state.severityFilter === 0; }

            var hazards = activeHazards(device);
            return hazards.length > 0;
        });
    }

    function activeHazards(device) {
        return (device.hazards || []).filter(function (h) {
            return state.hazardFilter[h.hazard] !== false;
        });
    }

    function renderTable() {
        var $body = $root.find('[data-role="rows"]').empty();
        var rows = visibleDevices().filter(function (d) { return d.risk >= 2; });

        if (! rows.length) {
            $('<tr/>').append(
                $('<td/>', { colspan: 6, 'class': 'tw:px-2 tw:py-2 tw:opacity-70',
                             text: t('no_devices_at_risk') })
            ).appendTo($body);
            return;
        }

        var dir = state.sort.dir === 'asc' ? 1 : -1;
        var key = state.sort.key;

        rows.sort(function (a, b) {
            var av, bv;
            var ah = activeHazards(a)[0] || {};
            var bh = activeHazards(b)[0] || {};

            switch (key) {
                case 'device':   av = a.display_name || ''; bv = b.display_name || ''; break;
                case 'location': av = a.location || '';     bv = b.location || '';     break;
                case 'hazard':   av = ah.hazard_label || ''; bv = bh.hazard_label || ''; break;
                case 'time':     av = ah.peak_time || '';   bv = bh.peak_time || '';   break;
                default:         av = a.risk; bv = b.risk;
            }

            if (av < bv) { return -1 * dir; }
            if (av > bv) { return 1 * dir; }
            // Stable secondary ordering so equal rows do not jump between refreshes.
            return (b.risk - a.risk) || String(a.display_name).localeCompare(String(b.display_name));
        });

        rows.forEach(function (device) {
            var hazard = activeHazards(device)[0] || {};
            var meta = levelMeta(device.risk);

            var $tr = $('<tr/>', { 'data-device': device.device_id });
            $tr.append($('<td/>', { html: '<span style="color:' + meta.color + '">' + meta.emoji + '</span>' }));
            $tr.append($('<td/>', {
                html: '<span' + (device.up ? '' : ' class="tw:opacity-70" title="' + esc(t('status_down')) + '"')
                      + '>' + esc(device.display_name) + '</span>'
                      + (device.up ? '' : ' <span style="color:#cc2b2b">●</span>')
            }));
            $tr.append($('<td/>', { text: device.location || '' }));
            $tr.append($('<td/>', {
                html: esc((hazard.hazard_icon || '') + ' ' + (hazard.hazard_label || ''))
                      + (hazard.official ? ' <span class="wxrisk-badge" style="background:' + meta.color
                         + ';color:#fff">OFF</span>' : '')
            }));
            $tr.append($('<td/>', { text: hazard.detail || '' }));
            $tr.append($('<td/>', { text: hazard.peak_time_short || '' }));

            $body.append($tr);
        });
    }

    // ----------------------------------------------------------------- map

    function deviceIcon(device) {
        var meta = levelMeta(device.risk);
        var size = device.risk >= 3 ? 18 : 14;
        var classes = 'wxrisk-marker'
            + (device.up ? '' : ' is-down')
            + (device.disabled ? ' is-disabled' : '');

        return L.divIcon({
            className: '',
            html: '<div class="' + classes + '" style="width:' + size + 'px;height:' + size
                  + 'px;background:' + meta.color + '"></div>',
            iconSize: [size, size],
            iconAnchor: [size / 2, size / 2]
        });
    }

    function devicePopup(device) {
        var meta = levelMeta(device.risk);
        var html = '<div class="wxrisk-popup">';

        html += '<h4><a href="' + esc(deviceBase + '/' + device.device_id) + '">'
             + esc(device.display_name) + '</a></h4>';

        html += '<div class="wxrisk-kv"><span>' + esc(t('popup.status')) + '</span><b style="color:'
             + (device.up ? '#2e9e4f' : '#cc2b2b') + '">'
             + esc(device.disabled ? t('status_disabled') : (device.up ? t('status_up') : t('status_down')))
             + '</b></div>';

        if (device.location) {
            html += '<div class="wxrisk-kv"><span>' + esc(t('popup.location')) + '</span><span>'
                 + esc(device.location) + '</span></div>';
        }

        html += '<div class="wxrisk-kv"><span>' + esc(t('popup.weather_risk')) + '</span><b style="color:'
             + meta.color + '">' + esc(device.risk_label) + '</b></div>';

        if (device.current) {
            html += '<div class="wxrisk-sec"><b>' + esc(t('popup.current')) + '</b>';
            if (device.current.temperature !== null && device.current.temperature !== undefined) {
                html += '<div class="wxrisk-kv"><span>' + esc(t('popup.temperature'))
                     + '</span><span>' + device.current.temperature.toFixed(1) + ' °C</span></div>';
            }
            if (device.current.wind !== null && device.current.wind !== undefined) {
                html += '<div class="wxrisk-kv"><span>' + esc(t('popup.wind'))
                     + '</span><span>' + device.current.wind.toFixed(1) + ' m/s</span></div>';
            }
            if (device.current.gust !== null && device.current.gust !== undefined) {
                html += '<div class="wxrisk-kv"><span>' + esc(t('popup.gusts'))
                     + '</span><span>' + device.current.gust.toFixed(1) + ' m/s</span></div>';
            }
            if (device.current.precipitation !== null && device.current.precipitation !== undefined) {
                html += '<div class="wxrisk-kv"><span>' + esc(t('popup.precipitation'))
                     + '</span><span>' + device.current.precipitation.toFixed(1) + ' mm/h</span></div>';
            }
            html += '</div>';
        } else if (! device.has_forecast) {
            html += '<div class="wxrisk-sec wxrisk-muted">' + esc(t('popup.no_forecast')) + '</div>';
        }

        var hazards = activeHazards(device);
        if (hazards.length) {
            html += '<div class="wxrisk-sec"><b>' + esc(t('popup.warnings')) + '</b><div class="wxrisk-scroll">';
            hazards.forEach(function (h) {
                var hm = levelMeta(h.risk);
                html += '<div><span style="color:' + hm.color + '">' + hm.emoji + '</span> '
                     + esc(h.hazard_label) + ' — ' + esc(h.detail)
                     + (h.official ? ' <i class="wxrisk-muted">(official)</i>' : '')
                     + (h.inferred ? ' <i class="wxrisk-muted">(risk)</i>' : '')
                     + (h.peak_time_short ? ' <span class="wxrisk-muted">@' + esc(h.peak_time_short) + '</span>' : '')
                     + '</div>';
            });
            html += '</div></div>';

            html += '<div class="wxrisk-sec"><b>' + esc(t('popup.impact')) + '</b><ul style="margin:.2em 0 0 1em;padding:0">';
            var seen = {};
            hazards.forEach(function (h) {
                if (h.impact && ! seen[h.hazard]) { seen[h.hazard] = true;
                    html += '<li>' + esc(h.impact) + '</li>'; }
            });
            html += '</ul></div>';
        }

        if (device.sensitivity && device.sensitivity.length) {
            html += '<div class="wxrisk-sec wxrisk-muted">' + esc(t('popup.sensitivity')) + ': '
                 + esc(device.sensitivity.join(', ')) + '</div>';
        }

        html += '<div class="wxrisk-sec"><a href="' + esc(deviceBase + '/' + device.device_id) + '">'
             + esc(t('popup.device_page')) + '</a></div>';

        return html + '</div>';
    }

    function warningPopup(warning) {
        var meta = levelMeta(warning.risk);
        var html = '<div class="wxrisk-popup"><h4 style="color:' + meta.color + '">'
                 + esc((warning.hazard_icon || '') + ' ' + (warning.event_name || warning.title)) + '</h4>';

        html += '<div class="wxrisk-kv"><span>' + esc(t('warning_popup.severity')) + '</span><b style="color:'
             + meta.color + '">' + esc(warning.severity_label) + '</b></div>';

        if (warning.area) {
            html += '<div class="wxrisk-kv"><span>' + esc(t('warning_popup.area')) + '</span><span>'
                 + esc(warning.area) + '</span></div>';
        }
        if (warning.onset) {
            html += '<div class="wxrisk-kv"><span>' + esc(t('warning_popup.starts')) + '</span><span>'
                 + esc(new Date(warning.onset).toLocaleString()) + '</span></div>';
        }
        if (warning.expires) {
            html += '<div class="wxrisk-kv"><span>' + esc(t('warning_popup.ends')) + '</span><span>'
                 + esc(new Date(warning.expires).toLocaleString()) + '</span></div>';
        }

        html += '<div class="wxrisk-scroll">';
        // Only rendered when the source actually published them - nothing here
        // is generated by LibreNMS.
        if (warning.description) {
            html += '<div class="wxrisk-sec"><b>' + esc(t('warning_popup.description')) + '</b><div>'
                 + esc(warning.description) + '</div></div>';
        }
        if (warning.consequences) {
            html += '<div class="wxrisk-sec"><b>' + esc(t('warning_popup.consequences')) + '</b><div>'
                 + esc(warning.consequences) + '</div></div>';
        }
        if (warning.instruction) {
            html += '<div class="wxrisk-sec"><b>' + esc(t('warning_popup.instruction')) + '</b><div>'
                 + esc(warning.instruction) + '</div></div>';
        }
        html += '</div>';

        html += '<div class="wxrisk-sec wxrisk-muted">' + esc(t('warning_popup.source')) + ': '
             + esc(warning.source);
        if (warning.fetched_at) {
            html += ' · ' + esc(t('warning_popup.updated')) + ' '
                 + esc(new Date(warning.fetched_at).toLocaleTimeString());
        }
        html += '</div>';

        if (warning.source_url) {
            html += '<div><a href="' + esc(warning.source_url) + '" target="_blank" rel="noopener noreferrer">'
                 + esc(t('warning_popup.more')) + '</a></div>';
        }

        return html + '</div>';
    }

    function renderMap() {
        var map = get_map(mapId);
        if (! map || ! state.payload) { return; }

        // --- warning polygons ------------------------------------------------
        if (state.polygonLayer) { map.removeLayer(state.polygonLayer); state.polygonLayer = null; }

        if (state.payload.settings.show_warnings) {
            var shapes = [];
            (state.payload.warnings || []).forEach(function (warning) {
                if (state.hazardFilter[warning.hazard] === false) { return; }
                (warning.polygons || []).forEach(function (ring) {
                    if (! ring || ring.length < 3) { return; }
                    var polygon = L.polygon(ring, {
                        color: warning.risk_color,
                        weight: 1.5,
                        opacity: 0.85,
                        // Transparent enough that markers stay readable beneath.
                        fillColor: warning.risk_color,
                        fillOpacity: 0.16
                    });
                    polygon.bindPopup(warningPopup(warning), { maxWidth: 320 });
                    shapes.push(polygon);
                });
            });

            if (shapes.length) {
                state.polygonLayer = L.layerGroup(shapes).addTo(map);
                // Keep polygons under the markers.
                if (state.polygonLayer.bringToBack) { state.polygonLayer.bringToBack(); }
            }
        }

        // --- device markers --------------------------------------------------
        var devices = visibleDevices();
        state.markers = {};

        var markers = devices.map(function (device) {
            var marker = L.marker([device.lat, device.lng], {
                icon: deviceIcon(device),
                title: device.display_name,
                // Worse weather draws on top of calmer neighbours. Also read
                // back by the cluster icon builder to find the worst child.
                zIndexOffset: device.risk * 1000
            });
            marker.bindPopup(devicePopup(device), { maxWidth: 320 });
            state.markers[device.device_id] = marker;
            return marker;
        });

        if (state.cluster) {
            state.cluster.clearLayers();
        } else if (state.payload.settings.clustering && L.markerClusterGroup) {
            state.cluster = L.markerClusterGroup({
                maxClusterRadius: 45,
                // Cluster takes the colour of the WORST device inside it, so a
                // single red site is never hidden by 26 green ones.
                iconCreateFunction: function (cluster) {
                    var children = cluster.getAllChildMarkers();
                    var worst = 0;
                    for (var i = 0; i < children.length; i++) {
                        worst = Math.max(worst, children[i].options.zIndexOffset / 1000);
                    }
                    var meta = levelMeta(worst);
                    var size = worst >= 3 ? 40 : 34;
                    return L.divIcon({
                        html: '<div class="wxrisk-cluster" style="width:' + size + 'px;height:' + size
                              + 'px;background:' + meta.color + '">' + cluster.getChildCount() + '</div>',
                        className: '',
                        iconSize: L.point(size, size)
                    });
                }
            });
            map.addLayer(state.cluster);
        } else if (! state.cluster) {
            state.cluster = L.layerGroup().addTo(map);
        }

        if (state.cluster.addLayers) {
            state.cluster.addLayers(markers);
        } else {
            markers.forEach(function (m) { state.cluster.addLayer(m); });
        }

        // --- automatic positioning -------------------------------------------
        // Spec section 18: fit to the devices actually present, never to a
        // hard-coded country or city.
        var shouldFit = state.payload.settings.map_mode !== 'remember' || ! state.fitted;
        if (shouldFit && state.payload.bounds) {
            var b = state.payload.bounds;
            if (b.south === b.north && b.west === b.east) {
                map.setView([b.south, b.west], 11);   // single device
            } else {
                map.fitBounds([[b.south, b.west], [b.north, b.east]], { padding: [25, 25], maxZoom: 12 });
            }
            state.fitted = true;
        }
    }

    function render() {
        renderSummary();
        renderHazardToggles();
        renderTable();
        renderMap();

        var p = state.payload;
        if (p && p.devices.length === 0) {
            overlay((p.meta && p.meta.empty_reason) || t('no_devices'));
        } else {
            overlay(null);
        }
    }

    // ---------------------------------------------------------------- fetch

    function load() {
        if (state.loading) { return; }
        state.loading = true;

        $.ajax({
            type: 'POST',
            url: dataUrl,
            dataType: 'json',
            data: { id: widgetId, _token: csrfToken },
            success: function (data) {
                state.loading = false;

                if (! data || data.status !== 'ok') {
                    // Device data may still be absent, but the dashboard stays alive.
                    overlay((data && data.message) || t('error_generic'));
                    return;
                }

                state.payload = data;
                render();
            },
            error: function (xhr) {
                state.loading = false;
                var message = t('error_generic');
                if (xhr && xhr.status === 429) {
                    message = 'Too many refreshes - please wait a moment.';
                }
                overlay(message);
            }
        });
    }

    // ------------------------------------------------------------- listeners

    $root.on('click', '[data-level]', function () {
        var level = parseInt($(this).attr('data-level'), 10);
        // Clicking the active filter clears it.
        state.severityFilter = (state.severityFilter === level) ? 0 : level;
        render();
    });

    $root.on('click', '[data-hazard]', function () {
        var hazard = $(this).attr('data-hazard');
        state.hazardFilter[hazard] = state.hazardFilter[hazard] === false;
        render();
    });

    $root.on('click', 'th[data-sort]', function () {
        var key = $(this).attr('data-sort');
        if (state.sort.key === key) {
            state.sort.dir = state.sort.dir === 'asc' ? 'desc' : 'asc';
        } else {
            state.sort.key = key;
            state.sort.dir = key === 'risk' ? 'desc' : 'asc';
        }
        renderTable();
    });

    // Spec section 14: clicking a row centres the map on that device.
    $root.on('click', 'tbody tr[data-device]', function () {
        var id = $(this).attr('data-device');
        var marker = state.markers[id];
        var map = get_map(mapId);
        if (! marker || ! map) { return; }

        var latlng = marker.getLatLng();
        map.setView(latlng, Math.max(map.getZoom(), 11), { animate: true });

        if (state.cluster && state.cluster.zoomToShowLayer) {
            state.cluster.zoomToShowLayer(marker, function () { marker.openPopup(); });
        } else {
            marker.openPopup();
        }
    });

    // ------------------------------------------------------------------ boot

    // The same load order the core world-map widget uses. L.Control.Locate is
    // required even though this widget never calls it: init_map() adds the
    // locate control itself whenever the page is served over HTTPS, and would
    // throw without it.
    loadjs('js/leaflet.js', function () {
        loadjs('js/leaflet.markercluster.js', function () {
            loadjs('js/L.Control.Locate.min.js', function () {
            var map = init_map(mapId, mapConfig);

            // Scroll-wheel zoom is enabled only while the pointer is engaged,
            // matching the core world-map widget so the dashboard still scrolls.
            map.scrollWheelZoom.disable();

            load();

            $('#' + rootId)
                .on('click', function () { var m = get_map(mapId); if (m) { m.scrollWheelZoom.enable(); } })
                .on('mouseleave', function () { var m = get_map(mapId); if (m) { m.scrollWheelZoom.disable(); } })
                .on('resize', function () { var m = get_map(mapId); if (m) { m.invalidateSize(); } })
                .on('refresh', function () {
                    var m = get_map(mapId);
                    if (m) { m.invalidateSize(); }
                    load();
                })
                .on('destroy', function () {
                    state.markers = {};
                    state.cluster = null;
                    state.polygonLayer = null;
                    destroy_map(mapId);
                });
            });
        });
    });
})();
</script>
