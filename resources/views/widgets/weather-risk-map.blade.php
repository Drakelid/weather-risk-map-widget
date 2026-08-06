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
    <div class="wxrisk-summary tw:shrink-0">
        <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-2">
            <span class="tw:font-bold tw:text-sm tw:tracking-wide">
                {{ __('weather-risk-map::widget.summary_heading') }}
            </span>
            <span class="tw:text-xs tw:opacity-70" data-role="window-label"></span>
            <span class="wxrisk-badge tw:hidden" data-role="degraded"></span>
            <span class="tw:grow"></span>
            <span class="tw:text-xs tw:opacity-90" data-role="highest"></span>
        </div>

        {{-- Counters double as severity filters (spec section 13). --}}
        <div class="tw:flex tw:flex-wrap tw:gap-1 tw:mt-1" data-role="counters"></div>

        {{-- Hazard toggles (spec section 11). --}}
        <div class="tw:flex tw:flex-wrap tw:gap-1 tw:mt-1" data-role="hazard-toggles"></div>
    </div>

    {{--
        Active warnings strip.

        The single biggest usability gain over a count: an operator sees WHAT
        is warned, WHERE, WHEN and HOW MANY devices sit inside it, without
        hunting for polygons on the map. Clicking a card flies the map to that
        warning and dims the others.
    --}}
    <div class="wxrisk-warnings tw:shrink-0 tw:mt-2 tw:hidden" data-role="warnings-wrap">
        <div class="tw:flex tw:items-center tw:gap-2 tw:mb-1">
            <span class="tw:text-xs tw:font-semibold tw:opacity-80">
                {{ __('weather-risk-map::widget.warnings_heading') }}
            </span>
            <span class="tw:text-xs tw:opacity-60" data-role="warnings-count"></span>
            <button type="button" class="wxrisk-linkbtn tw:hidden" data-role="clear-selection">
                {{ __('weather-risk-map::widget.show_all') }}
            </button>
        </div>
        <div class="wxrisk-strip" data-role="warning-cards"></div>
    </div>

    {{-- ------------------------------------------------------------- map --}}
    <div class="wxrisk-map-wrap tw:relative tw:grow tw:min-h-[220px] tw:mt-2 tw:rounded tw:overflow-hidden
                tw:border tw:border-black/10 dark:tw:border-black/60">
        <div id="{{ $wid }}-map" class="wxrisk-map tw:absolute tw:inset-0"></div>

        {{-- Map toolbar. Sits above Leaflet panes (z-index 400) but below popups. --}}
        <div class="wxrisk-toolbar" data-role="toolbar">
            <button type="button" data-action="fit-devices" title="{{ __('weather-risk-map::widget.fit_devices') }}">⌖</button>
            <button type="button" data-action="fit-warnings" title="{{ __('weather-risk-map::widget.fit_warnings') }}">⚠</button>
            <button type="button" data-action="toggle-warnings" title="{{ __('weather-risk-map::widget.toggle_warnings') }}">▨</button>
            <button type="button" data-action="toggle-legend" title="{{ __('weather-risk-map::widget.legend') }}">?</button>
        </div>

        {{-- Legend, hidden by default so it never eats map space on a small widget. --}}
        <div class="wxrisk-legend tw:hidden" data-role="legend">
            <div class="wxrisk-legend-title">{{ __('weather-risk-map::widget.legend') }}</div>
            <div data-role="legend-items"></div>
            <div class="wxrisk-legend-row">
                <span class="wxrisk-legend-swatch wxrisk-marker is-down" style="background:var(--wx-normal)"></span>
                <span>{{ __('weather-risk-map::widget.legend_down') }}</span>
            </div>
            <div class="wxrisk-legend-hint">{{ __('weather-risk-map::widget.legend_hint') }}</div>
        </div>

        <div data-role="overlay"
             class="tw:absolute tw:inset-0 tw:flex tw:items-center tw:justify-center tw:text-sm tw:text-center tw:px-3
                    tw:bg-white/80 dark:tw:bg-[#22262b]/85 tw:z-[500]">
            <span data-role="overlay-text">{{ __('weather-risk-map::widget.loading') }}</span>
        </div>
    </div>

    {{-- ---------------------------------------------- infrastructure table --}}
    <div class="wxrisk-table-wrap tw:shrink-0 tw:mt-2 tw:max-h-[34%] tw:overflow-y-auto
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
 *
 * THEMING
 * LibreNMS toggles `class="dark"` on <html> (librenms.js applySiteStyle), and
 * supports a "device" preference that follows prefers-color-scheme and can flip
 * at runtime. Everything below therefore keys off `html.dark` rather than a
 * media query, so the widget follows the user's LibreNMS setting rather than
 * the OS when those disagree.
 *
 * The risk palette lives in custom properties so a single definition drives
 * CSS *and* the JavaScript that paints markers, polygons and chips. The dark
 * variants are lighter and less saturated: the same #d9a406 that reads well on
 * white is muddy on a dark map, and #cc2b2b on dark is close to unreadable at
 * marker size.
 */
.wxrisk-widget {
    --wx-normal:   #2e9e4f;
    --wx-info:     #2f7ed8;
    --wx-moderate: #b8860b;
    --wx-high:     #d9600f;
    --wx-severe:   #cc2b2b;
    --wx-extreme:  #7b2ff7;

    --wx-surface:      rgba(0,0,0,.06);
    --wx-surface-hover: rgba(0,0,0,.13);
    --wx-border:       rgba(0,0,0,.12);
    --wx-panel:        rgba(255,255,255,.94);
    --wx-panel-fg:     #222;
    --wx-link:         #2f7ed8;
    --wx-tile-filter:  none;
}
html.dark .wxrisk-widget {
    --wx-normal:   #3fc36b;
    --wx-info:     #5aa9f0;
    --wx-moderate: #f0c437;
    --wx-high:     #ff9440;
    --wx-severe:   #ff5f5f;
    --wx-extreme:  #b980ff;

    --wx-surface:      rgba(255,255,255,.08);
    --wx-surface-hover: rgba(255,255,255,.16);
    --wx-border:       rgba(255,255,255,.14);
    --wx-panel:        rgba(34,38,43,.95);
    --wx-panel-fg:     #e6e6e6;
    --wx-link:         #7fb3e8;
    --wx-tile-filter:  brightness(.7) saturate(.7);
}
.wxrisk-widget .wxrisk-chip {
    display: inline-flex; align-items: center; gap: .3em;
    padding: .12em .5em; border-radius: 999px;
    font-size: 11px; line-height: 1.6; cursor: pointer;
    border: 1px solid transparent; user-select: none;
    background: var(--wx-surface); transition: background .12s, opacity .12s;
}
.wxrisk-widget .wxrisk-chip:hover { background: var(--wx-surface-hover); }
.wxrisk-widget .wxrisk-chip.is-active { border-color: currentColor; font-weight: 600; }
.wxrisk-widget .wxrisk-chip.is-off { opacity: .35; text-decoration: line-through; }
.wxrisk-widget .wxrisk-chip .wxrisk-dot {
    width: 8px; height: 8px; border-radius: 50%; display: inline-block; flex: none;
}

.wxrisk-widget .wxrisk-badge {
    display: inline-block; padding: .1em .5em; border-radius: 3px;
    font-size: 10px; font-weight: 600;
    background: #d9a406; color: #1a1a1a;
}

.wxrisk-widget .wxrisk-linkbtn {
    background: none; border: 0; padding: 0;
    font-size: 11px; text-decoration: underline; cursor: pointer;
    color: var(--wx-link);
}

/* ---------------------------------------------------- warnings strip ---- */
.wxrisk-widget .wxrisk-strip {
    display: flex; gap: 6px; overflow-x: auto; overflow-y: hidden;
    padding-bottom: 3px; scrollbar-width: thin;
}
.wxrisk-widget .wxrisk-wcard {
    flex: 0 0 auto; max-width: 260px; min-width: 168px;
    border-left: 4px solid currentColor;
    border-radius: 3px; padding: .3em .5em; cursor: pointer;
    background: var(--wx-surface); user-select: none;
    transition: background .12s, opacity .12s, transform .12s;
}
.wxrisk-widget .wxrisk-wcard:hover { background: var(--wx-surface-hover); transform: translateY(-1px); }
/* When one card is selected the rest recede, so the map/strip agree. */
.wxrisk-widget .wxrisk-strip.has-selection .wxrisk-wcard { opacity: .42; }
.wxrisk-widget .wxrisk-strip.has-selection .wxrisk-wcard.is-selected {
    opacity: 1; box-shadow: 0 0 0 2px currentColor inset;
}
.wxrisk-widget .wxrisk-wcard.is-flat { cursor: default; }
.wxrisk-widget .wxrisk-wcard-top {
    display: flex; align-items: center; gap: .35em;
    font-size: 11.5px; font-weight: 600; line-height: 1.35;
}
.wxrisk-widget .wxrisk-wcard-name {
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.wxrisk-widget .wxrisk-wcard-sub {
    font-size: 10.5px; opacity: .78; line-height: 1.35;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.wxrisk-widget .wxrisk-when {
    flex: none; font-size: 9px; font-weight: 700; letter-spacing: .03em;
    padding: 0 .35em; border-radius: 2px; color: #fff;
}
.wxrisk-widget .wxrisk-when.is-now { background: var(--wx-severe); }
.wxrisk-widget .wxrisk-when.is-later { background: #6b7280; }

/* ------------------------------------------------------------- table ---- */
.wxrisk-widget .wxrisk-table th { font-weight: 600; white-space: nowrap; }
.wxrisk-widget .wxrisk-table tbody tr { cursor: pointer; border-top: 1px solid var(--wx-border); }
.wxrisk-widget .wxrisk-table tbody tr:hover { background: var(--wx-surface); }
.wxrisk-widget .wxrisk-table td { padding: .2em .5em; vertical-align: top; }

/* ------------------------------------------------------------ markers --- */
/* Weather risk is the fill; LibreNMS down state is the border. */
.wxrisk-marker { border-radius: 50%; border: 2px solid rgba(255,255,255,.85); box-sizing: border-box; }
html.dark .wxrisk-marker { border-color: rgba(255,255,255,.5); }
.wxrisk-marker.is-down { border-color: #111; border-width: 3px; border-style: double; }
.wxrisk-marker.is-disabled { opacity: .45; }
/* Severe and above get a halo so they are findable at a glance on a NOC wall. */
.wxrisk-marker.is-severe { box-shadow: 0 0 0 3px color-mix(in srgb, var(--wx-severe) 40%, transparent), 0 0 6px rgba(0,0,0,.5); }
.wxrisk-marker.is-extreme { box-shadow: 0 0 0 3px color-mix(in srgb, var(--wx-extreme) 45%, transparent), 0 0 6px rgba(0,0,0,.5); }
/* Devices inside the selected warning. */
.wxrisk-marker.is-highlit { box-shadow: 0 0 0 3px #fff, 0 0 0 5px #111; }

.wxrisk-cluster {
    border-radius: 50%; color: #fff; font-weight: 700; font-size: 12px;
    display: flex; align-items: center; justify-content: center;
    border: 2px solid rgba(255,255,255,.7); box-shadow: 0 0 4px rgba(0,0,0,.4);
}

/* ------------------------------------------------- map toolbar/legend --- */
.wxrisk-widget .wxrisk-toolbar {
    position: absolute; top: 8px; right: 8px; z-index: 450;
    display: flex; flex-direction: column; gap: 3px;
}
.wxrisk-widget .wxrisk-toolbar button {
    width: 26px; height: 26px; line-height: 1; padding: 0;
    font-size: 13px; cursor: pointer; border-radius: 3px;
    background: #fff; color: #333; border: 1px solid rgba(0,0,0,.25);
    box-shadow: 0 1px 3px rgba(0,0,0,.3);
}
html.dark .wxrisk-widget .wxrisk-toolbar button {
    background: #2b3036; color: #e6e6e6; border-color: #14171a;
}
.wxrisk-widget .wxrisk-toolbar button:hover { background: #eee; }
html.dark .wxrisk-widget .wxrisk-toolbar button:hover { background: #3a4048; }
.wxrisk-widget .wxrisk-toolbar button.is-off { opacity: .45; }

.wxrisk-widget .wxrisk-legend {
    position: absolute; left: 8px; bottom: 8px; z-index: 450;
    background: var(--wx-panel); color: var(--wx-panel-fg);
    border: 1px solid var(--wx-border); border-radius: 3px;
    padding: .45em .6em; font-size: 10.5px; line-height: 1.7;
    box-shadow: 0 1px 4px rgba(0,0,0,.3); max-width: 210px;
}
.wxrisk-widget .wxrisk-legend-title { font-weight: 700; margin-bottom: .2em; }
.wxrisk-widget .wxrisk-legend-row { display: flex; align-items: center; gap: .4em; }
.wxrisk-widget .wxrisk-legend-swatch {
    width: 11px; height: 11px; border-radius: 50%; display: inline-block; flex: none;
}
.wxrisk-widget .wxrisk-legend-hint { margin-top: .3em; opacity: .7; font-size: 9.5px; line-height: 1.4; }

/* ------------------------------------------------------ leaflet chrome -- */
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
/* Tiles are bright; a slight dim keeps markers and polygons readable without
   requiring a paid dark basemap. Driven by the same token block as everything
   else, so light mode simply resolves to `none`. */
.wxrisk-map .leaflet-tile-pane { filter: var(--wx-tile-filter); }

/* Hover label on a warning area, so its identity is readable without a click. */
.wxrisk-map .leaflet-tooltip.wxrisk-wtip {
    font-size: 11px; font-weight: 600; padding: 2px 6px;
    background: var(--wx-panel); color: var(--wx-panel-fg);
    border: 0; border-left: 4px solid #888; border-radius: 2px;
    box-shadow: 0 1px 4px rgba(0,0,0,.4);
}
.wxrisk-map .leaflet-tooltip.wxrisk-wtip::before { display: none; }

.wxrisk-widget .wxrisk-popup { font-size: 12px; min-width: 210px; max-width: 290px; }
.wxrisk-widget .wxrisk-popup h4 { margin: 0 0 .3em; font-size: 13px; font-weight: 700; }
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

    // Per-widget state, kept local so several Weather Risk Map widgets on one
    // dashboard never share filters or map handles.
    var state = {
        payload: null,
        severityFilter: settings.min_severity || 0,
        hazardFilter: {},          // hazard -> false when switched off client-side
        sort: { key: 'risk', dir: 'desc' },
        markers: {},
        cluster: null,
        warningLayers: {},         // warning id -> [L.Polygon]
        polygonLayer: null,
        selectedWarning: null,
        showWarnings: settings.show_warnings !== false,
        showLegend: false,
        fitted: false,
        loading: false,
        palette: null,
        themeObserver: null,
        wasDark: null
    };

    // `fallback` is only used if the stylesheet has not applied yet; the live
    // colour always comes from the CSS custom properties, so light and dark
    // are defined in exactly one place.
    var LEVELS = [
        { key: 'extreme',  value: 5, fallback: '#7b2ff7', emoji: '🟣' },
        { key: 'severe',   value: 4, fallback: '#cc2b2b', emoji: '🔴' },
        { key: 'high',     value: 3, fallback: '#d9600f', emoji: '🟠' },
        { key: 'moderate', value: 2, fallback: '#b8860b', emoji: '🟡' },
        { key: 'info',     value: 1, fallback: '#2f7ed8', emoji: '🔵' },
        { key: 'normal',   value: 0, fallback: '#2e9e4f', emoji: '🟢' }
    ];

    /**
     * Resolve the risk palette from CSS for the theme currently in force.
     *
     * Read once per render rather than per marker: getComputedStyle() forces
     * style resolution, and a 1000-device map would otherwise call it
     * thousands of times.
     */
    function refreshPalette() {
        var computed = window.getComputedStyle($root[0]);
        state.palette = {};

        LEVELS.forEach(function (level) {
            var value = computed.getPropertyValue('--wx-' + level.key);
            state.palette[level.key] = (value && value.trim()) || level.fallback;
        });
    }

    function isDark() {
        return document.documentElement.classList.contains('dark');
    }

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

    /**
     * Level descriptor with its colour resolved for the current theme.
     * Returns a fresh object so a stale colour can never be cached by a caller.
     */
    function levelMeta(value) {
        var base = LEVELS[LEVELS.length - 1];
        for (var i = 0; i < LEVELS.length; i++) {
            if (LEVELS[i].value === value) { base = LEVELS[i]; break; }
        }

        return {
            key: base.key,
            value: base.value,
            emoji: base.emoji,
            color: (state.palette && state.palette[base.key]) || base.fallback
        };
    }

    function overlay(text) {
        var $o = $root.find('[data-role="overlay"]');
        if (text === null) { $o.addClass('tw:hidden'); return; }
        $o.removeClass('tw:hidden').find('[data-role="overlay-text"]').text(text);
    }

    function hhmm(iso) {
        if (! iso) { return null; }
        var d = new Date(iso);
        return isNaN(d.getTime()) ? null : d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    // ------------------------------------------------------------- warnings

    /** Warnings the user has not filtered out by hazard. */
    function visibleWarnings() {
        if (! state.payload) { return []; }
        return (state.payload.warnings || []).filter(function (w) {
            return state.hazardFilter[w.hazard] !== false;
        });
    }

    /** How many currently-shown devices sit inside this warning. */
    function affectedDeviceIds(warningId) {
        if (! state.payload) { return []; }
        return state.payload.devices.filter(function (d) {
            return (d.warnings || []).some(function (w) { return w.id === warningId; });
        }).map(function (d) { return d.device_id; });
    }

    function isActiveNow(warning) {
        var now = Date.now();
        var onset = warning.onset ? new Date(warning.onset).getTime() : null;
        var expires = warning.expires ? new Date(warning.expires).getTime() : null;
        if (onset !== null && onset > now) { return false; }
        if (expires !== null && expires < now) { return false; }
        return true;
    }

    function renderWarnings() {
        var warnings = visibleWarnings();
        var $wrap = $root.find('[data-role="warnings-wrap"]');
        var $strip = $root.find('[data-role="warning-cards"]').empty();

        if (! warnings.length) {
            $wrap.addClass('tw:hidden');
            return;
        }
        $wrap.removeClass('tw:hidden');

        // Worst and soonest first - what a NOC needs to see without scrolling.
        warnings = warnings.slice().sort(function (a, b) {
            if (b.risk !== a.risk) { return b.risk - a.risk; }
            var an = isActiveNow(a) ? 0 : 1, bn = isActiveNow(b) ? 0 : 1;
            if (an !== bn) { return an - bn; }
            return String(a.onset || '').localeCompare(String(b.onset || ''));
        });

        $root.find('[data-role="warnings-count"]').text('(' + warnings.length + ')');

        warnings.forEach(function (warning) {
            var meta = levelMeta(warning.risk);
            var affected = affectedDeviceIds(warning.id).length;
            var hasGeometry = (warning.polygons || []).length > 0;
            var now = isActiveNow(warning);

            var affectedLabel = affected === 0
                ? t('no_devices_affected', 'no devices')
                : (affected === 1
                    ? t('one_device_affected', '1 device')
                    : String(t('devices_affected', ':count devices')).replace(':count', affected));

            var period = warning.period || '';
            var sub = [warning.area, period, affectedLabel].filter(Boolean).join(' · ');

            var $card = $('<div/>', {
                'class': 'wxrisk-wcard' + (hasGeometry ? '' : ' is-flat')
                         + (state.selectedWarning === warning.id ? ' is-selected' : ''),
                'style': 'color:' + meta.color,
                'data-warning': warning.id,
                'title': hasGeometry ? '' : t('no_geometry')
            });

            $card.append(
                $('<div/>', { 'class': 'wxrisk-wcard-top' }).append(
                    $('<span/>', { 'class': 'wxrisk-when ' + (now ? 'is-now' : 'is-later'),
                                   text: now ? t('active_now', 'NOW') : t('upcoming', 'Later') }),
                    $('<span/>', { text: warning.hazard_icon || '⚠' }),
                    $('<span/>', { 'class': 'wxrisk-wcard-name tw:text-gray-800 dark:tw:text-dark-white-100',
                                   text: warning.event_name || warning.title })
                )
            );
            $card.append($('<div/>', { 'class': 'wxrisk-wcard-sub', text: sub }));

            $strip.append($card);
        });

        $strip.toggleClass('has-selection', state.selectedWarning !== null);
        $root.find('[data-role="clear-selection"]').toggleClass('tw:hidden', state.selectedWarning === null);
    }

    /** Bounds covering every polygon of one warning, or of all warnings. */
    function warningBounds(warningId) {
        var bounds = null;

        Object.keys(state.warningLayers).forEach(function (id) {
            if (warningId && id !== warningId) { return; }
            state.warningLayers[id].forEach(function (layer) {
                var b = layer.getBounds();
                if (! b || ! b.isValid()) { return; }

                // Clone rather than seed from b directly: L.latLngBounds()
                // returns the SAME object when handed a bounds, so extending it
                // would mutate the polygon's own internal _bounds.
                if (! bounds) {
                    bounds = L.latLngBounds(b.getSouthWest(), b.getNorthEast());
                } else {
                    bounds.extend(b);
                }
            });
        });

        return bounds;
    }

    function selectWarning(warningId) {
        var map = get_map(mapId);
        if (! map) { return; }

        // Clicking the selected card again clears the selection.
        state.selectedWarning = (state.selectedWarning === warningId) ? null : warningId;

        applyWarningEmphasis();
        renderWarnings();
        renderMarkers();

        if (state.selectedWarning) {
            var bounds = warningBounds(state.selectedWarning);
            if (bounds && bounds.isValid()) {
                map.fitBounds(bounds, { padding: [30, 30], maxZoom: 10 });
            }
            var layers = state.warningLayers[state.selectedWarning];
            if (layers && layers.length) { layers[0].openPopup(); }
        }
    }

    /** Fade unselected warning areas so the selected one reads clearly. */
    function applyWarningEmphasis() {
        Object.keys(state.warningLayers).forEach(function (id) {
            var dim = state.selectedWarning !== null && id !== state.selectedWarning;
            state.warningLayers[id].forEach(function (layer) {
                var base = layer.options._baseFill || 0.2;
                layer.setStyle({
                    fillOpacity: dim ? 0.04 : base,
                    opacity: dim ? 0.25 : 0.95,
                    weight: (state.selectedWarning === id) ? 3.5 : 2
                });
                if (state.selectedWarning === id && layer.bringToFront) { layer.bringToFront(); }
            });
        });
    }

    // ------------------------------------------------------------ rendering

    function renderSummary() {
        var p = state.payload;
        if (! p) { return; }

        $root.find('[data-role="window-label"]').text('— ' + t('window.' + p.window_hours, p.window_hours + 'h'));

        var $counters = $root.find('[data-role="counters"]').empty();
        LEVELS.forEach(function (level) {
            var count = p.counts[level.key] || 0;
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

        var $highest = $root.find('[data-role="highest"]').empty();
        if (p.highest) {
            var meta = levelMeta(p.highest.risk);
            $highest.html(
                '<b>' + esc(t('highest_risk')) + ':</b> <span style="color:' + meta.color + '">'
                + esc(p.highest.hazard) + ' — ' + esc(p.highest.location || p.highest.device)
                + (p.highest.time ? ' — ' + esc(p.highest.time) : '') + '</span>'
            );
        }

        var $degraded = $root.find('[data-role="degraded"]');
        if (p.meta && (p.meta.degraded || p.meta.stale)) {
            $degraded.removeClass('tw:hidden')
                .text(p.meta.degraded ? t('degraded') : t('cached_notice'))
                .attr('title', p.meta.message || t('cached_notice'));
        } else {
            $degraded.addClass('tw:hidden');
        }

        $root.find('[data-role="attribution"]').text(
            (p.meta && p.meta.attribution ? p.meta.attribution : '')
            + (p.meta && p.meta.cells ? '  ·  ' + p.meta.cells + ' forecast areas / ' + p.meta.locations + ' sites' : '')
        );

        var $legend = $root.find('[data-role="legend-items"]').empty();
        LEVELS.slice().reverse().forEach(function (level) {
            $('<div/>', { 'class': 'wxrisk-legend-row' }).append(
                $('<span/>', { 'class': 'wxrisk-legend-swatch', style: 'background:' + level.color }),
                $('<span/>', { text: t('levels.' + level.key, level.key) })
            ).appendTo($legend);
        });
    }

    function renderHazardToggles() {
        var $wrap = $root.find('[data-role="hazard-toggles"]').empty();
        var enabled = (state.payload && state.payload.settings && state.payload.settings.hazards)
                      || settings.hazards || {};

        Object.keys(enabled).forEach(function (hazard) {
            if (! enabled[hazard]) { return; }   // disabled in widget settings
            var on = state.hazardFilter[hazard] !== false;

            // Worst level currently present for this hazard, so a chip carrying
            // real risk is visually distinct from a quiet one.
            var worst = 0;
            (state.payload ? state.payload.devices : []).forEach(function (d) {
                (d.hazards || []).forEach(function (h) {
                    if (h.hazard === hazard) { worst = Math.max(worst, h.risk); }
                });
            });
            var meta = levelMeta(worst);

            $('<span/>', {
                'class': 'wxrisk-chip' + (on ? '' : ' is-off'),
                'data-hazard': hazard,
                'html': '<span class="wxrisk-dot" style="background:' + meta.color + '"></span>'
                        + esc(hazard.charAt(0).toUpperCase() + hazard.slice(1))
            }).appendTo($wrap);
        });
    }

    /** Devices passing the severity filter and at least one enabled hazard. */
    function visibleDevices() {
        if (! state.payload) { return []; }

        return state.payload.devices.filter(function (device) {
            if (device.risk < state.severityFilter) { return false; }
            if (device.risk === 0) { return state.severityFilter === 0; }
            return activeHazards(device).length > 0;
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

        // When a warning is selected the table narrows to its devices, so the
        // strip, the map and the table always describe the same thing.
        if (state.selectedWarning) {
            var ids = affectedDeviceIds(state.selectedWarning);
            rows = rows.filter(function (d) { return ids.indexOf(d.device_id) !== -1; });
        }

        if (! rows.length) {
            $('<tr/>').append($('<td/>', {
                colspan: 6, 'class': 'tw:px-2 tw:py-2 tw:opacity-70', text: t('no_devices_at_risk')
            })).appendTo($body);
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
            return (b.risk - a.risk) || String(a.display_name).localeCompare(String(b.display_name));
        });

        rows.forEach(function (device) {
            var hazard = activeHazards(device)[0] || {};
            var meta = levelMeta(device.risk);

            var $tr = $('<tr/>', { 'data-device': device.device_id });
            $tr.append($('<td/>', { html: '<span style="color:' + meta.color + '">' + meta.emoji + '</span>' }));
            $tr.append($('<td/>', {
                html: esc(device.display_name)
                      + (device.up ? '' : ' <span style="color:#cc2b2b" title="' + esc(t('status_down')) + '">●</span>')
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

    function deviceIcon(device, highlit) {
        var meta = levelMeta(device.risk);
        var size = device.risk >= 3 ? 18 : 14;
        var classes = 'wxrisk-marker'
            + (device.up ? '' : ' is-down')
            + (device.disabled ? ' is-disabled' : '')
            + (device.risk === 4 ? ' is-severe' : '')
            + (device.risk === 5 ? ' is-extreme' : '')
            + (highlit ? ' is-highlit' : '');

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
                if (h.impact && ! seen[h.hazard]) { seen[h.hazard] = true; html += '<li>' + esc(h.impact) + '</li>'; }
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
        var affected = affectedDeviceIds(warning.id).length;

        var html = '<div class="wxrisk-popup"><h4 style="color:' + meta.color + '">'
                 + esc((warning.hazard_icon || '') + ' ' + (warning.event_name || warning.title)) + '</h4>';

        html += '<div class="wxrisk-kv"><span>' + esc(t('warning_popup.severity')) + '</span><b style="color:'
             + meta.color + '">' + esc(warning.severity_label) + '</b></div>';

        if (warning.area) {
            html += '<div class="wxrisk-kv"><span>' + esc(t('warning_popup.area')) + '</span><span>'
                 + esc(warning.area) + '</span></div>';
        }
        html += '<div class="wxrisk-kv"><span>' + esc(t('infrastructure_at_risk')) + '</span><b>'
             + affected + '</b></div>';

        if (warning.onset) {
            html += '<div class="wxrisk-kv"><span>' + esc(t('warning_popup.starts')) + '</span><span>'
                 + esc(new Date(warning.onset).toLocaleString()) + '</span></div>';
        }
        if (warning.expires) {
            html += '<div class="wxrisk-kv"><span>' + esc(t('warning_popup.ends')) + '</span><span>'
                 + esc(new Date(warning.expires).toLocaleString()) + '</span></div>';
        }

        html += '<div class="wxrisk-scroll">';
        // Rendered only when the source actually published them - nothing here
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

        html += '<div class="wxrisk-sec wxrisk-muted">' + esc(t('warning_popup.source')) + ': ' + esc(warning.source);
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

    function renderPolygons() {
        var map = get_map(mapId);
        if (! map) { return; }

        if (state.polygonLayer) { map.removeLayer(state.polygonLayer); }
        state.polygonLayer = null;
        state.warningLayers = {};

        if (! state.showWarnings) { return; }

        var shapes = [];

        visibleWarnings().forEach(function (warning) {
            var now = isActiveNow(warning);
            // Warnings not yet in force are drawn dashed and lighter, so "now"
            // and "later" are distinguishable at a glance on the map itself.
            // Dark tiles are dimmed, so the fill needs a little more presence.
            var baseFill = (now ? 0.22 : 0.10) + (isDark() ? 0.04 : 0);

            // Theme palette rather than warning.risk_color: the server hex is
            // tuned for a light background and goes muddy on the dark basemap.
            var wcolor = levelMeta(warning.risk).color;

            (warning.polygons || []).forEach(function (ring) {
                if (! ring || ring.length < 3) { return; }

                var polygon = L.polygon(ring, {
                    color: wcolor,
                    weight: 2,
                    opacity: 0.95,
                    dashArray: now ? null : '5,4',
                    fillColor: wcolor,
                    fillOpacity: baseFill,
                    _baseFill: baseFill
                });

                // Hover label: identify an area without having to click it.
                polygon.bindTooltip(
                    '<span style="color:' + wcolor + '">' + esc(warning.hazard_icon || '⚠') + '</span> '
                    + esc(warning.event_name || warning.title)
                    + (warning.area ? ' — ' + esc(warning.area) : ''),
                    { sticky: true, direction: 'top', className: 'wxrisk-wtip', opacity: 1 }
                );

                polygon.bindPopup(warningPopup(warning), { maxWidth: 320 });

                polygon.on('mouseover', function () {
                    if (state.selectedWarning !== null && state.selectedWarning !== warning.id) { return; }
                    this.setStyle({ fillOpacity: Math.min(0.42, baseFill + 0.2), weight: 3.5 });
                    if (this.bringToFront) { this.bringToFront(); }
                });
                polygon.on('mouseout', function () {
                    if (state.selectedWarning !== null && state.selectedWarning !== warning.id) { return; }
                    this.setStyle({ fillOpacity: baseFill, weight: state.selectedWarning === warning.id ? 3.5 : 2 });
                });
                polygon.on('click', function () { selectWarning(warning.id); });

                if (! state.warningLayers[warning.id]) { state.warningLayers[warning.id] = []; }
                state.warningLayers[warning.id].push(polygon);
                shapes.push(polygon);
            });
        });

        if (shapes.length) {
            state.polygonLayer = L.layerGroup(shapes).addTo(map);
            if (state.polygonLayer.bringToBack) { state.polygonLayer.bringToBack(); }
        }

        applyWarningEmphasis();
    }

    function renderMarkers() {
        var map = get_map(mapId);
        if (! map) { return; }

        var highlit = state.selectedWarning ? affectedDeviceIds(state.selectedWarning) : [];
        var devices = visibleDevices();
        state.markers = {};

        var markers = devices.map(function (device) {
            var isHighlit = highlit.indexOf(device.device_id) !== -1;
            var marker = L.marker([device.lat, device.lng], {
                icon: deviceIcon(device, isHighlit),
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
    }

    function fitDevices() {
        var map = get_map(mapId);
        if (! map || ! state.payload || ! state.payload.bounds) { return; }

        var b = state.payload.bounds;
        if (b.south === b.north && b.west === b.east) {
            map.setView([b.south, b.west], 11);       // single device
        } else {
            map.fitBounds([[b.south, b.west], [b.north, b.east]], { padding: [25, 25], maxZoom: 12 });
        }
    }

    function fitWarnings() {
        var map = get_map(mapId);
        var bounds = warningBounds(null);
        if (map && bounds && bounds.isValid()) {
            map.fitBounds(bounds, { padding: [30, 30], maxZoom: 10 });
        }
    }

    function renderMap() {
        renderPolygons();
        renderMarkers();

        // Spec section 18: fit to the devices actually present, never to a
        // hard-coded country or city.
        var shouldFit = state.payload.settings.map_mode !== 'remember' || ! state.fitted;
        if (shouldFit && state.payload.bounds) {
            fitDevices();
            state.fitted = true;
        }

        $root.find('[data-action="toggle-warnings"]').toggleClass('is-off', ! state.showWarnings);
    }

    function render() {
        refreshPalette();
        renderSummary();
        renderHazardToggles();
        renderWarnings();
        renderTable();
        renderMap();

        var p = state.payload;
        overlay(p && p.devices.length === 0
            ? ((p.meta && p.meta.empty_reason) || t('no_devices'))
            : null);
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
                    overlay((data && data.message) || t('error_generic'));
                    return;
                }

                // Drop a selection whose warning has expired between refreshes.
                if (state.selectedWarning
                    && ! (data.warnings || []).some(function (w) { return w.id === state.selectedWarning; })) {
                    state.selectedWarning = null;
                }

                state.payload = data;
                render();
            },
            error: function (xhr) {
                state.loading = false;
                overlay(xhr && xhr.status === 429
                    ? 'Too many refreshes - please wait a moment.'
                    : t('error_generic'));
            }
        });
    }

    // ------------------------------------------------------------- listeners

    $root.on('click', '[data-level]', function () {
        var level = parseInt($(this).attr('data-level'), 10);
        state.severityFilter = (state.severityFilter === level) ? 0 : level;   // click again to clear
        render();
    });

    $root.on('click', '[data-hazard]', function () {
        var hazard = $(this).attr('data-hazard');
        state.hazardFilter[hazard] = state.hazardFilter[hazard] === false;
        render();
    });

    $root.on('click', '[data-warning]', function () {
        var id = $(this).attr('data-warning');
        if ($(this).hasClass('is-flat')) { return; }   // no geometry to fly to
        selectWarning(id);
        renderTable();
    });

    $root.on('click', '[data-role="clear-selection"]', function () {
        state.selectedWarning = null;
        applyWarningEmphasis();
        renderWarnings();
        renderMarkers();
        renderTable();
    });

    $root.on('click', '.wxrisk-toolbar button', function () {
        var action = $(this).attr('data-action');

        if (action === 'fit-devices') { fitDevices(); }
        if (action === 'fit-warnings') { fitWarnings(); }
        if (action === 'toggle-warnings') {
            state.showWarnings = ! state.showWarnings;
            $(this).toggleClass('is-off', ! state.showWarnings);
            renderPolygons();
        }
        if (action === 'toggle-legend') {
            state.showLegend = ! state.showLegend;
            $root.find('[data-role="legend"]').toggleClass('tw:hidden', ! state.showLegend);
        }
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

        map.setView(marker.getLatLng(), Math.max(map.getZoom(), 11), { animate: true });

        if (state.cluster && state.cluster.zoomToShowLayer) {
            state.cluster.zoomToShowLayer(marker, function () { marker.openPopup(); });
        } else {
            marker.openPopup();
        }
    });

    // ------------------------------------------------------------ theming

    /**
     * Repaint when the LibreNMS theme changes, without a dashboard reload.
     *
     * CSS follows `html.dark` on its own, but anything JavaScript painted does
     * not: Leaflet polygon strokes and fills are inline SVG attributes, and
     * marker fills are inline styles baked into a divIcon at creation time.
     * Both are frozen at their light-theme colours until something rebuilds
     * them, so the theme is watched and the layers re-rendered.
     *
     * A MutationObserver on <html> is used rather than a prefers-color-scheme
     * listener because LibreNMS's 'light' and 'dark' preferences deliberately
     * override the OS; only the 'device' preference follows it, and in that
     * case applySiteStyle() still toggles the class, so this catches every path.
     */
    function watchTheme() {
        if (! window.MutationObserver) { return; }

        state.wasDark = isDark();

        state.themeObserver = new MutationObserver(function () {
            var dark = isDark();
            if (dark === state.wasDark) { return; }   // unrelated class change

            state.wasDark = dark;
            refreshPalette();

            if (state.payload) {
                render();
            }
        });

        state.themeObserver.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class']
        });
    }

    // ------------------------------------------------------------------ boot

    // The same load order the core world-map widget uses. L.Control.Locate is
    // required even though this widget never calls it: init_map() adds the
    // locate control itself whenever the page is served over HTTPS, and would
    // throw without it.
    loadjs('js/leaflet.js', function () {
        loadjs('js/leaflet.markercluster.js', function () {
            loadjs('js/L.Control.Locate.min.js', function () {
                var map = init_map(mapId, mapConfig);

                // Scroll-wheel zoom only while the pointer is engaged, matching
                // the core world-map widget so the dashboard still scrolls.
                map.scrollWheelZoom.disable();

                refreshPalette();
                watchTheme();
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
                        // The observer is on <html>, which outlives this widget,
                        // so it must be disconnected or it leaks on every
                        // dashboard edit and keeps a dead closure alive.
                        if (state.themeObserver) {
                            state.themeObserver.disconnect();
                            state.themeObserver = null;
                        }
                        state.markers = {};
                        state.cluster = null;
                        state.polygonLayer = null;
                        state.warningLayers = {};
                        destroy_map(mapId);
                    });
            });
        });
    });
})();
</script>
