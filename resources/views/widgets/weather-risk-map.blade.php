{{--
    Weather Risk Map widget shell.

    Renders immediately with no weather data: everything is fetched
    asynchronously from the data endpoint so a slow or dead weather provider
    cannot delay the LibreNMS dashboard.

    STYLING - deliberately no Tailwind.

    LibreNMS compiles Tailwind from its own tree only (resources/css/app.css has
    two @source directives plus the default project scan). This package lives
    outside that tree and is reached through a vendor symlink, so `tw:` classes
    used here are never generated - arbitrary values such as tw:min-h-[220px]
    are guaranteed missing, and anything that did work did so only because core
    happened to use the same utility. Note also that Tailwind v4 wants the
    prefix first (tw:dark:...), so the `dark:tw:...` form seen in parts of core
    is dead CSS.

    Everything below is therefore plain, self-contained, .wxrisk-scoped CSS.

    THEMING - LibreNMS toggles class="dark" on <html> (librenms.js
    applySiteStyle), including at runtime for the "device" preference. Colours
    live in custom properties so one definition drives both this stylesheet and
    the JavaScript that paints markers and polygons.
--}}
@php
    $wid = 'wxrisk-' . $id;
@endphp

<div id="{{ $wid }}" class="wxrisk-widget" data-reload="false">

    {{-- ---------------------------------------------------------- summary --}}
    <div class="wxrisk-summary">
        <div class="wxrisk-headline">
            <span class="wxrisk-title">{{ __('weather-risk-map::widget.summary_heading') }}</span>
            <span class="wxrisk-window" data-role="window-label"></span>
            <span class="wxrisk-badge wx-hidden" data-role="degraded"></span>
            <span class="wxrisk-spacer"></span>
            <span class="wxrisk-highest" data-role="highest"></span>
        </div>

        {{-- Counters double as severity filters (spec section 13). --}}
        <div class="wxrisk-chiprow" data-role="counters"></div>

        {{-- Hazard toggles (spec section 11). --}}
        <div class="wxrisk-chiprow" data-role="hazard-toggles"></div>
    </div>

    {{--
        Active warnings strip.

        The single biggest usability gain over a bare count: an operator sees
        WHAT is warned, WHERE, WHEN and HOW MANY devices sit inside it, without
        hunting for polygons. Clicking a card flies the map to that warning and
        dims the others.
    --}}
    <div class="wxrisk-warnings wx-hidden" data-role="warnings-wrap">
        <div class="wxrisk-warnings-head">
            <span class="wxrisk-subtitle">{{ __('weather-risk-map::widget.warnings_heading') }}</span>
            <span class="wxrisk-muted-text" data-role="warnings-count"></span>
            <button type="button" class="wxrisk-linkbtn wx-hidden" data-role="clear-selection">
                {{ __('weather-risk-map::widget.show_all') }}
            </button>
        </div>
        <div class="wxrisk-strip" data-role="warning-cards"></div>
        <div class="wxrisk-wdetail wx-hidden" data-role="warning-detail"></div>
    </div>

    {{-- ------------------------------------------------------------- map --}}
    <div class="wxrisk-map-wrap">
        <div id="{{ $wid }}-map" class="wxrisk-map"></div>

        {{-- Toolbar sits above Leaflet's panes but below its popups. --}}
        <div class="wxrisk-toolbar" data-role="toolbar">
            <button type="button" data-action="fit-devices" title="{{ __('weather-risk-map::widget.fit_devices') }}"><svg class="wxrisk-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="7"/><path d="M12 1v4M12 19v4M1 12h4M19 12h4"/></svg></button>
            <button type="button" data-action="fit-warnings" title="{{ __('weather-risk-map::widget.fit_warnings') }}"><svg class="wxrisk-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 2.5 20h19z"/><path d="M12 10v4M12 17h.01"/></svg></button>
            <button type="button" data-action="toggle-warnings" title="{{ __('weather-risk-map::widget.toggle_warnings') }}"><svg class="wxrisk-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="M4 7l8-3 8 3v10l-8 3-8-3z"/><path d="M4 11h16M4 15h16"/></svg></button>
            <button type="button" data-action="toggle-legend" title="{{ __('weather-risk-map::widget.legend') }}"><svg class="wxrisk-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7.5h.01"/></svg></button>
        </div>

        {{-- Legend, hidden by default so it never eats map space on a small widget. --}}
        <div class="wxrisk-legend wx-hidden" data-role="legend">
            <div class="wxrisk-legend-title">{{ __('weather-risk-map::widget.legend') }}</div>
            <div data-role="legend-items"></div>
            <div class="wxrisk-legend-row">
                <span class="wxrisk-legend-swatch wxrisk-marker is-down" style="background:var(--wx-normal)"></span>
                <span>{{ __('weather-risk-map::widget.legend_down') }}</span>
            </div>
            <div class="wxrisk-legend-hint">{{ __('weather-risk-map::widget.legend_hint') }}</div>
        </div>

        <div class="wxrisk-overlay" data-role="overlay">
            <span data-role="overlay-text">{{ __('weather-risk-map::widget.loading') }}</span>
        </div>
    </div>

    {{-- ---------------------------------------------- infrastructure table --}}
    <div class="wxrisk-table-wrap">
        <table class="wxrisk-table">
            <thead>
                <tr>
                    <th data-sort="risk">{{ __('weather-risk-map::widget.table.risk') }}</th>
                    <th data-sort="device">{{ __('weather-risk-map::widget.table.device') }}</th>
                    <th data-sort="location">{{ __('weather-risk-map::widget.table.location') }}</th>
                    <th data-sort="hazard">{{ __('weather-risk-map::widget.table.hazard') }}</th>
                    <th>{{ __('weather-risk-map::widget.table.peak') }}</th>
                    <th data-sort="time">{{ __('weather-risk-map::widget.table.time') }}</th>
                </tr>
            </thead>
            <tbody data-role="rows"></tbody>
        </table>
    </div>

    <div class="wxrisk-attribution" data-role="attribution"></div>
</div>

<style>
/* =====================================================================
 * Weather Risk Map - self-contained styles.
 * Every rule is scoped to .wxrisk-widget so nothing leaks into other
 * widgets, and nothing here depends on Tailwind being compiled.
 * ===================================================================== */

/* ------------------------------------------------------------ tokens --- */
/*
 * Dark risk colours are lighter and less saturated than their light
 * counterparts: the #b8860b that reads well on white is muddy on a dimmed
 * dark basemap, and #cc2b2b at 14px marker size is close to unreadable.
 *
 * Secondary text uses an explicit --wx-muted rather than opacity. Opacity
 * dimming compounds against a dark background and was the main cause of
 * hard-to-read text in the dark theme.
 */
.wxrisk-widget {
    --wx-normal:   #1f8a45;
    --wx-info:     #2f7ed8;
    --wx-moderate: #a8760a;
    --wx-high:     #c9560d;
    --wx-severe:   #c62222;
    --wx-extreme:  #7326e8;

    --wx-fg:            #23272b;
    --wx-muted:         #5b6470;
    --wx-surface:       rgba(0,0,0,.06);
    --wx-surface-hover: rgba(0,0,0,.13);
    --wx-border:        rgba(0,0,0,.14);
    --wx-panel:         rgba(255,255,255,.97);
    --wx-panel-fg:      #23272b;
    --wx-head-bg:       #e5e7eb;
    --wx-link:          #1f6fc4;
    --wx-scrim:         rgba(255,255,255,.86);
    --wx-tile-filter:   none;
}
html.dark .wxrisk-widget {
    --wx-normal:   #4ed07d;
    --wx-info:     #6ab4f7;
    --wx-moderate: #f5cc4d;
    --wx-high:     #ffa15c;
    --wx-severe:   #ff7070;
    --wx-extreme:  #c294ff;

    --wx-fg:            #e8eaed;
    --wx-muted:         #b3bcc7;
    --wx-surface:       rgba(255,255,255,.09);
    --wx-surface-hover: rgba(255,255,255,.17);
    --wx-border:        rgba(255,255,255,.16);
    --wx-panel:         rgba(38,43,49,.97);
    --wx-panel-fg:      #e8eaed;
    --wx-head-bg:       #3e444c;
    --wx-link:          #8cc2f0;
    --wx-scrim:         rgba(30,34,39,.88);
    --wx-tile-filter:   brightness(.72) saturate(.75);
}

/* ------------------------------------------------------------ layout --- */
.wxrisk-widget {
    display: flex;
    flex-direction: column;
    height: 100%;
    font-size: 13px;
    line-height: 1.45;
    color: var(--wx-fg);
}
.wxrisk-widget .wx-hidden { display: none !important; }
.wxrisk-widget .wxrisk-summary,
.wxrisk-widget .wxrisk-warnings,
.wxrisk-widget .wxrisk-table-wrap,
.wxrisk-widget .wxrisk-attribution { flex: 0 0 auto; }

.wxrisk-widget .wxrisk-headline {
    display: flex; flex-wrap: wrap; align-items: center; gap: 8px;
}
.wxrisk-widget .wxrisk-spacer { flex: 1 1 auto; }
.wxrisk-widget .wxrisk-title {
    font-size: 14px; font-weight: 700; letter-spacing: .02em;
}
.wxrisk-widget .wxrisk-subtitle { font-size: 12.5px; font-weight: 600; }
.wxrisk-widget .wxrisk-window { font-size: 12.5px; color: var(--wx-muted); }
.wxrisk-widget .wxrisk-highest { font-size: 12.5px; }
.wxrisk-widget .wxrisk-muted-text { font-size: 12px; color: var(--wx-muted); }
.wxrisk-widget .wx-dim { color: var(--wx-muted); }
.wxrisk-widget .wxrisk-chiprow {
    display: flex; flex-wrap: wrap; gap: 4px; margin-top: 5px;
}
.wxrisk-widget .wxrisk-attribution {
    margin-top: 4px; font-size: 11px; color: var(--wx-muted);
}

/* ------------------------------------------------------------- chips --- */
.wxrisk-widget .wxrisk-chip {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 9px; border-radius: 999px;
    font-size: 12.5px; line-height: 1.5; cursor: pointer;
    border: 1px solid transparent; user-select: none;
    background: var(--wx-surface);
    transition: background .12s, opacity .12s;
}
.wxrisk-widget .wxrisk-chip:hover { background: var(--wx-surface-hover); }
.wxrisk-widget .wxrisk-chip.is-active { border-color: currentColor; font-weight: 700; }
.wxrisk-widget .wxrisk-chip.is-off { opacity: .45; text-decoration: line-through; }
.wxrisk-widget .wxrisk-chip b { font-size: 13px; }
.wxrisk-widget .wxrisk-dot {
    width: 10px; height: 10px; border-radius: 50%; flex: none;
    display: inline-block; vertical-align: -1px;
}

/*
 * Inline SVG icons, sized in em so they track whatever text they sit beside.
 * These replaced emoji, which rendered as tofu wherever the glyph was missing
 * and could never match the theme palette.
 */
.wxrisk-widget .wxrisk-ico,
.wxrisk-popup .wxrisk-ico,
.leaflet-tooltip .wxrisk-ico {
    width: 1.05em; height: 1.05em; flex: none;
    display: inline-block; vertical-align: -0.16em;
}
.wxrisk-widget .wxrisk-toolbar button .wxrisk-ico {
    width: 16px; height: 16px; vertical-align: middle;
}

.wxrisk-widget .wxrisk-badge {
    display: inline-block; padding: 2px 7px; border-radius: 3px;
    font-size: 11px; font-weight: 700;
    background: #d9a406; color: #1a1a1a;
}

.wxrisk-widget .wxrisk-linkbtn {
    background: none; border: 0; padding: 0;
    font-size: 12px; text-decoration: underline; cursor: pointer;
    color: var(--wx-link);
}

/* --------------------------------------------------- warnings strip ---- */
.wxrisk-widget .wxrisk-warnings { margin-top: 8px; }
.wxrisk-widget .wxrisk-warnings-head {
    display: flex; align-items: center; gap: 8px; margin-bottom: 4px;
}
.wxrisk-widget .wxrisk-strip {
    display: flex; gap: 6px; overflow-x: auto; overflow-y: hidden;
    padding-bottom: 3px; scrollbar-width: thin;
}
.wxrisk-widget .wxrisk-wcard {
    flex: 0 0 auto; max-width: 280px; min-width: 180px;
    border-left: 4px solid currentColor;
    border-radius: 3px; padding: 5px 8px; cursor: pointer;
    background: var(--wx-surface); user-select: none;
    transition: background .12s, opacity .12s, transform .12s;
}
.wxrisk-widget .wxrisk-wcard:hover {
    background: var(--wx-surface-hover); transform: translateY(-1px);
}
/* When one card is selected the rest recede, so strip and map agree. */
.wxrisk-widget .wxrisk-strip.has-selection .wxrisk-wcard { opacity: .5; }
.wxrisk-widget .wxrisk-strip.has-selection .wxrisk-wcard.is-selected {
    opacity: 1; box-shadow: 0 0 0 2px currentColor inset;
}
/* No map area published: still readable, just not locatable. */
.wxrisk-widget .wxrisk-wcard.is-flat { border-left-style: dashed; }

/* Selected warning's full text, shown in place instead of on the map. */
.wxrisk-widget .wxrisk-wdetail {
    margin-top: 6px; padding: 8px 10px;
    background: var(--wx-surface);
    border: 1px solid var(--wx-border); border-radius: 3px;
    max-height: 190px; overflow-y: auto;
}
.wxrisk-widget .wxrisk-wdetail .wxrisk-popup { max-width: none; min-width: 0; }
.wxrisk-widget .wxrisk-wdetail .wxrisk-popup .wxrisk-scroll { max-height: none; overflow: visible; }
.wxrisk-widget .wxrisk-wdetail .wxrisk-kv { max-width: 340px; }
.wxrisk-widget .wxrisk-wcard-top {
    display: flex; align-items: center; gap: 5px;
    font-size: 13px; font-weight: 600; line-height: 1.4;
}
.wxrisk-widget .wxrisk-wcard-name {
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    color: var(--wx-fg);
}
.wxrisk-widget .wxrisk-wcard-sub {
    font-size: 12px; line-height: 1.4; color: var(--wx-muted);
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.wxrisk-widget .wxrisk-when {
    flex: none; font-size: 10.5px; font-weight: 700; letter-spacing: .04em;
    padding: 1px 5px; border-radius: 2px; color: #fff;
}
.wxrisk-widget .wxrisk-when.is-now { background: var(--wx-severe); }
html.dark .wxrisk-widget .wxrisk-when.is-now { color: #1a1a1a; }
.wxrisk-widget .wxrisk-when.is-later { background: #6b7280; }

/* --------------------------------------------------------------- map --- */
.wxrisk-widget .wxrisk-map-wrap {
    position: relative; flex: 1 1 auto; min-height: 220px;
    margin-top: 8px; border-radius: 4px; overflow: hidden;
    border: 1px solid var(--wx-border);
}
.wxrisk-widget .wxrisk-map { position: absolute; inset: 0; }
.wxrisk-widget .wxrisk-overlay {
    position: absolute; inset: 0; z-index: 500;
    display: flex; align-items: center; justify-content: center;
    text-align: center; padding: 0 14px;
    font-size: 13px; color: var(--wx-fg);
    background: var(--wx-scrim);
}

/* ------------------------------------------------------------- table --- */
.wxrisk-widget .wxrisk-table-wrap {
    margin-top: 8px; max-height: 34%; overflow-y: auto;
    border: 1px solid var(--wx-border); border-radius: 4px;
}
.wxrisk-widget .wxrisk-table { width: 100%; font-size: 12.5px; border-collapse: collapse; }
.wxrisk-widget .wxrisk-table thead th {
    position: sticky; top: 0; z-index: 2;
    background: var(--wx-head-bg); color: var(--wx-fg);
    font-weight: 700; white-space: nowrap; text-align: left;
    padding: 5px 8px;
}
.wxrisk-widget .wxrisk-table th[data-sort] { cursor: pointer; }
.wxrisk-widget .wxrisk-table tbody tr {
    cursor: pointer; border-top: 1px solid var(--wx-border);
}
.wxrisk-widget .wxrisk-table tbody tr:hover { background: var(--wx-surface); }
.wxrisk-widget .wxrisk-table td { padding: 4px 8px; vertical-align: top; }
.wxrisk-widget .wxrisk-table .wxrisk-empty { color: var(--wx-muted); padding: 8px; }

/* Expanded weather detail, shown in place of moving the map. */
.wxrisk-widget .wxrisk-detailrow > td {
    background: var(--wx-surface);
    padding: 8px 12px;
    cursor: default;
    border-top: 0;
}
.wxrisk-widget .wxrisk-detailrow .wxrisk-popup {
    max-width: none; min-width: 0;
}
.wxrisk-widget .wxrisk-detailrow .wxrisk-popup .wxrisk-scroll { max-height: none; }
/* The row already names the device; the popup heading would just repeat it. */
.wxrisk-widget .wxrisk-detailrow .wxrisk-popup h4 { font-size: 13.5px; }
.wxrisk-widget .wxrisk-detailrow .wxrisk-kv { max-width: 320px; }

/* Explicit "centre the map here" control. */
.wxrisk-widget .wxrisk-locate {
    background: none; border: 0; padding: 0 2px; margin-left: 3px;
    color: var(--wx-muted); cursor: pointer; vertical-align: -2px;
    opacity: 0; transition: opacity .12s, color .12s;
}
.wxrisk-widget .wxrisk-table tbody tr:hover .wxrisk-locate { opacity: 1; }
.wxrisk-widget .wxrisk-locate:hover { color: var(--wx-link); }
.wxrisk-widget .wxrisk-locate:focus { opacity: 1; outline: 1px dotted currentColor; }
.wxrisk-widget .wxrisk-locate .wxrisk-ico { width: 13px; height: 13px; }

/* ----------------------------------------------------------- markers --- */
/* Weather risk is the fill; LibreNMS down state is the border. */
.wxrisk-marker { border-radius: 50%; border: 2px solid rgba(255,255,255,.9); box-sizing: border-box; }
html.dark .wxrisk-marker { border-color: rgba(255,255,255,.55); }
.wxrisk-marker.is-down { border-color: #111; border-width: 3px; border-style: double; }
html.dark .wxrisk-marker.is-down { border-color: #000; }
.wxrisk-marker.is-disabled { opacity: .45; }
/* Severe and above get a halo so they are findable on a NOC wall. */
.wxrisk-marker.is-severe { box-shadow: 0 0 0 3px color-mix(in srgb, var(--wx-severe) 40%, transparent), 0 0 6px rgba(0,0,0,.5); }
.wxrisk-marker.is-extreme { box-shadow: 0 0 0 3px color-mix(in srgb, var(--wx-extreme) 45%, transparent), 0 0 6px rgba(0,0,0,.5); }
/* Devices inside the selected warning. */
.wxrisk-marker.is-highlit { box-shadow: 0 0 0 3px #fff, 0 0 0 5px #111; }

/*
 * The explicitly selected device.
 *
 * Since selecting no longer moves the map, the marker has to be findable
 * without it. A pulsing ring reads at a glance across a wall display, where a
 * static colour change of a 14px dot does not.
 */
.wxrisk-marker.is-selected {
    box-shadow: 0 0 0 3px #fff, 0 0 0 6px #111;
    animation: wxrisk-pulse 1.4s ease-out infinite;
}
html.dark .wxrisk-marker.is-selected { box-shadow: 0 0 0 3px #111, 0 0 0 6px #fff; }

/* A selection hidden inside a cluster: the cluster pulses in its place. */
.wxrisk-cluster.is-selected { animation: wxrisk-pulse 1.4s ease-out infinite; }

@keyframes wxrisk-pulse {
    0%   { outline: 0 solid rgba(255,255,255,.75); outline-offset: 0; }
    70%  { outline: 9px solid rgba(255,255,255,0); outline-offset: 2px; }
    100% { outline: 0 solid rgba(255,255,255,0); outline-offset: 0; }
}

/* Pulsing outline around the selected warning area. */
.wxrisk-map .wxrisk-halo { animation: wxrisk-halo-pulse 1.8s ease-in-out infinite; }
@keyframes wxrisk-halo-pulse {
    0%, 100% { stroke-opacity: .15; }
    50%      { stroke-opacity: .55; }
}

/* Respect a reduced-motion preference; the static ring still identifies it. */
@media (prefers-reduced-motion: reduce) {
    .wxrisk-marker.is-selected,
    .wxrisk-cluster.is-selected,
    .wxrisk-map .wxrisk-halo { animation: none; }
    .wxrisk-map .wxrisk-halo { stroke-opacity: .45; }
}

.wxrisk-cluster {
    border-radius: 50%; color: #fff; font-weight: 700; font-size: 13px;
    display: flex; align-items: center; justify-content: center;
    border: 2px solid rgba(255,255,255,.75); box-shadow: 0 0 4px rgba(0,0,0,.45);
}
html.dark .wxrisk-cluster { color: #14171a; }

/* --------------------------------------------------- toolbar / legend -- */
.wxrisk-widget .wxrisk-toolbar {
    position: absolute; top: 8px; right: 8px; z-index: 450;
    display: flex; flex-direction: column; gap: 3px;
}
.wxrisk-widget .wxrisk-toolbar button {
    width: 28px; height: 28px; line-height: 1; padding: 0;
    font-size: 14px; cursor: pointer; border-radius: 3px;
    background: var(--wx-panel); color: var(--wx-panel-fg);
    border: 1px solid var(--wx-border);
    box-shadow: 0 1px 3px rgba(0,0,0,.35);
}
.wxrisk-widget .wxrisk-toolbar button:hover { background: var(--wx-surface-hover); }
.wxrisk-widget .wxrisk-toolbar button.is-off { opacity: .5; }

.wxrisk-widget .wxrisk-legend {
    position: absolute; left: 8px; bottom: 8px; z-index: 450;
    background: var(--wx-panel); color: var(--wx-panel-fg);
    border: 1px solid var(--wx-border); border-radius: 3px;
    padding: 7px 9px; font-size: 12px; line-height: 1.65;
    box-shadow: 0 1px 4px rgba(0,0,0,.35); max-width: 230px;
}
.wxrisk-widget .wxrisk-legend-title { font-weight: 700; margin-bottom: 3px; font-size: 12.5px; }
.wxrisk-widget .wxrisk-legend-row { display: flex; align-items: center; gap: 6px; }
.wxrisk-widget .wxrisk-legend-swatch {
    width: 12px; height: 12px; border-radius: 50%; display: inline-block; flex: none;
}
.wxrisk-widget .wxrisk-legend-hint {
    margin-top: 5px; font-size: 11px; line-height: 1.45; color: var(--wx-muted);
}

/* ----------------------------------------------------- leaflet chrome -- */
html.dark .wxrisk-map .leaflet-popup-content-wrapper,
html.dark .wxrisk-map .leaflet-popup-tip {
    background: #262b31; color: #e8eaed; box-shadow: 0 1px 8px rgba(0,0,0,.6);
}
html.dark .wxrisk-map .leaflet-bar a,
html.dark .wxrisk-map .leaflet-control-layers {
    background: #262b31; color: #e8eaed; border-color: #14171a;
}
html.dark .wxrisk-map .leaflet-bar a:hover { background: #3a4048; }
html.dark .wxrisk-map .leaflet-control-attribution {
    background: rgba(20,23,26,.85); color: #c3c9d1;
}
html.dark .wxrisk-map .leaflet-control-attribution a { color: #8cc2f0; }
/* Tiles are bright; dimming keeps markers and polygons readable without a
   paid dark basemap. Light mode resolves the token to `none`. */
.wxrisk-map .leaflet-tile-pane { filter: var(--wx-tile-filter); }

/* Hover label on a warning area, so its identity reads without a click. */
.wxrisk-map .leaflet-tooltip.wxrisk-wtip {
    font-size: 12.5px; font-weight: 600; padding: 3px 7px;
    background: var(--wx-panel); color: var(--wx-panel-fg);
    border: 0; border-left: 4px solid #888; border-radius: 2px;
    box-shadow: 0 1px 4px rgba(0,0,0,.4);
}
.wxrisk-map .leaflet-tooltip.wxrisk-wtip::before { display: none; }

/* ------------------------------------------------------------ popups --- */
.wxrisk-popup { font-size: 13px; line-height: 1.5; min-width: 220px; max-width: 300px; }
.wxrisk-popup h4 { margin: 0 0 5px; font-size: 14px; font-weight: 700; }
.wxrisk-popup .wxrisk-kv { display: flex; justify-content: space-between; gap: 12px; }
.wxrisk-popup .wxrisk-sec {
    margin-top: 7px; padding-top: 5px; border-top: 1px solid rgba(128,128,128,.4);
}
.wxrisk-popup .wxrisk-muted { color: var(--wx-muted); }
html.dark .wxrisk-popup .wxrisk-muted { color: #b3bcc7; }
.wxrisk-popup .wxrisk-scroll { max-height: 170px; overflow-y: auto; }
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
        haloLayer: null,
        selectedWarning: null,
        showWarnings: settings.show_warnings !== false,
        showLegend: false,
        positioned: false,
        expandedDevice: null,
        loading: false,
        palette: null,
        themeObserver: null,
        wasDark: null
    };

    // `fallback` is only used if the stylesheet has not applied yet; the live
    // colour always comes from the CSS custom properties, so light and dark
    // are defined in exactly one place.
    var LEVELS = [
        { key: 'extreme',  value: 5, fallback: '#7326e8' },
        { key: 'severe',   value: 4, fallback: '#c62222' },
        { key: 'high',     value: 3, fallback: '#c9560d' },
        { key: 'moderate', value: 2, fallback: '#a8760a' },
        { key: 'info',     value: 1, fallback: '#2f7ed8' },
        { key: 'normal',   value: 0, fallback: '#1f8a45' }
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


    /*
     * Inline SVG rather than emoji.
     *
     * The previous glyphs rendered as tofu on common setups: U+2316 and U+25A8
     * are absent from many symbol fonts, and the coloured circles U+1F7E0-1F7E3
     * are Emoji 12.0 (2019). Several hazard emoji (U+1F327 cloud-with-rain,
     * U+1F321 thermometer) also default to *text* presentation and render
     * monochrome or blank without a variation selector.
     *
     * SVG also inherits currentColor, so risk indicators finally match the
     * theme palette - emoji have fixed colours that never did.
     */
    function svgIcon(inner, filled) {
        var paint = filled
            ? 'fill="currentColor" stroke="none"'
            : 'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';

        return '<svg class="wxrisk-ico" viewBox="0 0 24 24" ' + paint + ' aria-hidden="true">' + inner + '</svg>';
    }

    var HAZARD_PATHS = {
        wind:      '<path d="M3 8h11a3 3 0 1 0-3-3"/><path d="M3 13h15a3 3 0 1 1-3 3"/><path d="M3 18h8"/>',
        storm:     '<path d="M21 9A8 8 0 1 0 18 17.5"/><path d="M14 12.5a3.5 3.5 0 1 1-3.5 3.5"/>',
        lightning: '<path d="M13 2 5 14h6l-2 8 9-13h-6z"/>',
        rain:      '<path d="M6.5 14a4.5 4.5 0 0 1 .8-8.9 5.5 5.5 0 0 1 10.4 1.4A3.8 3.8 0 0 1 17.5 14z"/><path d="M8 17.5l-1 3M12 17.5l-1 3M16 17.5l-1 3"/>',
        flood:     '<path d="M2.5 8c3.2-2.4 5.2 2.4 8.3 0s5.2-2.4 8.3 0"/><path d="M2.5 13c3.2-2.4 5.2 2.4 8.3 0s5.2-2.4 8.3 0"/><path d="M2.5 18c3.2-2.4 5.2 2.4 8.3 0s5.2-2.4 8.3 0"/>',
        snow:      '<path d="M12 2.5v19M4 7l16 9.5M20 7L4 16.5"/>',
        icing:     '<path d="M12 2.5S17.5 9 17.5 12a5.5 5.5 0 0 1-11 0C6.5 9 12 2.5 12 2.5z"/><path d="M8.5 13.5h7"/>',
        heat:      '<path d="M14 14.8V4a2 2 0 1 0-4 0v10.8a4 4 0 1 0 4 0z"/><path d="M12 6.5v8"/>',
        cold:      '<path d="M14 14.8V4a2 2 0 1 0-4 0v10.8a4 4 0 1 0 4 0z"/><path d="M12 11.5v3"/>',
        other:     '<path d="M12 3 2.5 20h19z"/><path d="M12 10v4M12 17h.01"/>'
    };

    var LOCATE_PATH = '<circle cx="12" cy="12" r="6.5"/>'
                    + '<path d="M12 1.5v3M12 19.5v3M1.5 12h3M19.5 12h3"/>';

    function hazardSvg(hazard) {
        var key = Object.prototype.hasOwnProperty.call(HAZARD_PATHS, hazard) ? hazard : 'other';
        return svgIcon(HAZARD_PATHS[key], key === 'lightning');
    }

    /** Risk indicator: a themed CSS dot, replacing the coloured-circle emoji. */
    function riskDot(meta, px) {
        var size = px ? ';width:' + px + 'px;height:' + px + 'px' : '';
        return '<span class="wxrisk-dot" style="background:' + meta.color + size + '"></span>';
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
            color: (state.palette && state.palette[base.key]) || base.fallback
        };
    }

    function overlay(text) {
        var $o = $root.find('[data-role="overlay"]');
        if (text === null) { $o.addClass('wx-hidden'); return; }
        $o.removeClass('wx-hidden').find('[data-role="overlay-text"]').text(text);
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
            $wrap.addClass('wx-hidden');
            return;
        }
        $wrap.removeClass('wx-hidden');

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
                    $('<span/>', { html: hazardSvg(warning.hazard) }),
                    $('<span/>', { 'class': 'wxrisk-wcard-name',
                                   text: warning.event_name || warning.title })
                )
            );
            $card.append($('<div/>', { 'class': 'wxrisk-wcard-sub', text: sub }));

            $strip.append($card);
        });

        $strip.toggleClass('has-selection', state.selectedWarning !== null);
        $root.find('[data-role="clear-selection"]').toggleClass('wx-hidden', state.selectedWarning === null);

        renderWarningDetail();
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

    /**
     * Select a warning to read it.
     *
     * Deliberately does NOT move the map. Selecting a warning to find out what
     * it says should not cost the operator the view they had set up; the map
     * only changes emphasis, and the toolbar's zoom-to-warnings button remains
     * for when moving there is actually what you want.
     */
    function selectWarning(warningId) {
        // Clicking the selected card again clears the selection.
        state.selectedWarning = (state.selectedWarning === warningId) ? null : warningId;

        applyWarningEmphasis();
        renderWarnings();
        renderMarkers();
    }

    /** Find a warning in the current payload by id. */
    function warningById(id) {
        var found = null;
        (state.payload ? state.payload.warnings : []).forEach(function (w) {
            if (w.id === id) { found = w; }
        });
        return found;
    }

    /** Full text of the selected warning, shown in place rather than on the map. */
    function renderWarningDetail() {
        var $panel = $root.find('[data-role="warning-detail"]');
        var warning = state.selectedWarning ? warningById(state.selectedWarning) : null;

        if (! warning) {
            $panel.addClass('wx-hidden').empty();
            return;
        }

        var locatable = (warning.polygons || []).length > 0;

        // Explicit, never automatic: the halo says where it is, this goes there.
        $panel.removeClass('wx-hidden').html(
            warningPopup(warning)
            + (locatable
                ? '<button type="button" class="wxrisk-linkbtn wxrisk-showon" data-locate-warning="'
                  + esc(warning.id) + '">' + esc(t('show_on_map')) + '</button>'
                : '<span class="wxrisk-muted">' + esc(t('no_geometry')) + '</span>')
        );
    }


    /**
     * Draw a thick pulsing outline around the selected warning.
     *
     * Dimming the others tells you which one is selected but not where it is;
     * a halo makes it findable at a glance. It is a separate non-interactive
     * layer rather than a restyle of the polygon itself, so clicks, tooltips
     * and the crisp boundary stroke are all unaffected.
     */
    function updateWarningHalo() {
        var map = get_map(mapId);
        if (! map) { return; }

        if (state.haloLayer) {
            map.removeLayer(state.haloLayer);
            state.haloLayer = null;
        }

        if (! state.selectedWarning || ! state.showWarnings) { return; }

        var layers = state.warningLayers[state.selectedWarning];
        if (! layers || ! layers.length) { return; }

        var warning = warningById(state.selectedWarning);
        var colour = warning ? levelMeta(warning.risk).color : '#fff';

        var halos = layers.map(function (layer) {
            return L.polygon(layer.getLatLngs(), {
                color: colour,
                weight: 10,
                opacity: 0.35,
                fill: false,
                interactive: false,      // never steals a click from the polygon
                className: 'wxrisk-halo'
            });
        });

        state.haloLayer = L.layerGroup(halos).addTo(map);

        // Halo underneath, crisp boundary on top.
        layers.forEach(function (layer) {
            if (layer.bringToFront) { layer.bringToFront(); }
        });
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

        updateWarningHalo();
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
            var meta = levelMeta(level.value);
            $('<span/>', {
                'class': 'wxrisk-chip' + (active ? ' is-active' : ''),
                'style': 'color:' + meta.color,
                'html': riskDot(meta) + '<b>' + count + '</b> <span class="wx-dim">'
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
            $degraded.removeClass('wx-hidden')
                .text(p.meta.degraded ? t('degraded') : t('cached_notice'))
                .attr('title', p.meta.message || t('cached_notice'));
        } else {
            $degraded.addClass('wx-hidden');
        }

        $root.find('[data-role="attribution"]').text(
            (p.meta && p.meta.attribution ? p.meta.attribution : '')
            + (p.meta && p.meta.cells ? '  ·  ' + p.meta.cells + ' forecast areas / ' + p.meta.locations + ' sites' : '')
        );

        var $legend = $root.find('[data-role="legend-items"]').empty();
        LEVELS.slice().reverse().forEach(function (level) {
            $('<div/>', { 'class': 'wxrisk-legend-row' }).append(
                $('<span/>', { 'class': 'wxrisk-legend-swatch', style: 'background:' + levelMeta(level.value).color }),
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

        // When a locatable warning is selected the table narrows to its
        // devices, so strip, map and table describe the same thing. A warning
        // published without geometry matches no device, so filtering on it
        // would blank the table for no useful reason.
        if (state.selectedWarning) {
            var selected = warningById(state.selectedWarning);
            if (selected && (selected.polygons || []).length) {
                var ids = affectedDeviceIds(state.selectedWarning);
                rows = rows.filter(function (d) { return ids.indexOf(d.device_id) !== -1; });
            }
        }

        if (! rows.length) {
            $('<tr/>').append($('<td/>', {
                colspan: 6, 'class': 'wxrisk-empty', text: t('no_devices_at_risk')
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
            $tr.append($('<td/>', { html: riskDot(meta, 11) }));
            $tr.append($('<td/>', {
                html: esc(device.display_name)
                      + (device.up ? '' : ' <span class="wxrisk-dot" style="background:var(--wx-severe)" title="'
                         + esc(t('status_down')) + '"></span>')
                      // Locating on the map is now an explicit action rather
                      // than a side effect of wanting to read the forecast.
                      + ' <button type="button" class="wxrisk-locate" data-locate="' + device.device_id
                      + '" title="' + esc(t('locate')) + '">' + svgIcon(LOCATE_PATH) + '</button>'
            }));
            $tr.append($('<td/>', { text: device.location || '' }));
            $tr.append($('<td/>', {
                html: hazardSvg(hazard.hazard) + ' ' + esc(hazard.hazard_label || '')
                      + (hazard.official ? ' <span class="wxrisk-badge" style="background:' + meta.color
                         + ';color:#fff">OFF</span>' : '')
            }));
            $tr.append($('<td/>', { text: hazard.detail || '' }));
            $tr.append($('<td/>', { text: hazard.peak_time_short || '' }));

            $body.append($tr);

            // Re-open whatever the operator had expanded before the refresh,
            // so a 10-minute reload does not close what they were reading.
            if (state.expandedDevice === String(device.device_id)) {
                $body.append(detailRow(device));
            }
        });
    }

    /** Inline detail row holding the same content as the map popup. */
    function detailRow(device) {
        return $('<tr/>', { 'class': 'wxrisk-detailrow', 'data-detail': device.device_id })
            .append($('<td/>', { colspan: 6, html: devicePopup(device) }));
    }

    // ----------------------------------------------------------------- map

    function deviceIcon(device, highlit, selected) {
        var meta = levelMeta(device.risk);
        var size = device.risk >= 3 ? 18 : 14;
        if (selected) { size += 4; }        // the selected device reads first
        var classes = 'wxrisk-marker'
            + (device.up ? '' : ' is-down')
            + (device.disabled ? ' is-disabled' : '')
            + (device.risk === 4 ? ' is-severe' : '')
            + (device.risk === 5 ? ' is-extreme' : '')
            + (highlit ? ' is-highlit' : '')
            + (selected ? ' is-selected' : '');

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
                html += '<div>' + riskDot(hm) + ' '
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
                 + hazardSvg(warning.hazard) + ' '
                 + esc(warning.event_name || warning.title) + '</h4>';

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
                    '<span style="color:' + wcolor + '">' + hazardSvg(warning.hazard) + '</span> '
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
            var isSelected = state.expandedDevice === String(device.device_id);
            var marker = L.marker([device.lat, device.lng], {
                icon: deviceIcon(device, isHighlit, isSelected),
                title: device.display_name,
                // Worse weather draws on top of calmer neighbours; the selected
                // device outranks everything.
                zIndexOffset: isSelected ? 100000 : device.risk * 1000,
                // Carried explicitly rather than derived from zIndexOffset:
                // the cluster icon needs the real risk, and overloading the
                // z-index for selection would otherwise corrupt its colour.
                wxRisk: device.risk
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
                    var holdsSelected = false;
                    for (var i = 0; i < children.length; i++) {
                        worst = Math.max(worst, children[i].options.wxRisk || 0);
                        if (children[i].options.zIndexOffset === 100000) { holdsSelected = true; }
                    }
                    var meta = levelMeta(worst);
                    var size = worst >= 3 ? 40 : 34;
                    // A clustered selection would otherwise be invisible, so
                    // the cluster hiding it pulses in its place.
                    return L.divIcon({
                        html: '<div class="wxrisk-cluster' + (holdsSelected ? ' is-selected' : '')
                              + '" style="width:' + size + 'px;height:' + size
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


    // ----------------------------------------------------------- position

    /**
     * Where the map opens, per the widget's "Default map mode" setting:
     *
     *   auto     - fit the bounds of the visible devices on every refresh
     *   fixed    - always open at the configured lat/lng/zoom
     *   remember - restore wherever this operator last panned to
     *
     * "remember" persists to localStorage rather than the widget row on
     * purpose: it is a per-operator preference, several people commonly share
     * one dashboard, and writing widget settings needs dashboard update
     * permission that a read-only NOC account will not have.
     */
    var VIEW_KEY = 'wxrisk.view.' + widgetId;

    function storedView() {
        try {
            var raw = window.localStorage.getItem(VIEW_KEY);
            if (! raw) { return null; }

            var view = JSON.parse(raw);
            if (typeof view.lat !== 'number' || typeof view.lng !== 'number'
                || typeof view.zoom !== 'number') {
                return null;
            }
            return view;
        } catch (e) {
            return null;   // private mode, quota, or corrupt value
        }
    }

    function storeView() {
        var map = get_map(mapId);
        if (! map || settings.map_mode !== 'remember') { return; }

        try {
            var c = map.getCenter();
            window.localStorage.setItem(VIEW_KEY, JSON.stringify({
                lat: c.lat, lng: c.lng, zoom: map.getZoom()
            }));
        } catch (e) {
            // localStorage unavailable - remembering simply stops working.
        }
    }

    /** The configured fixed position, or null when it is unusable. */
    function fixedView() {
        if (settings.init_lat === null || settings.init_lat === undefined
            || settings.init_lng === null || settings.init_lng === undefined) {
            return null;
        }

        return {
            lat: Number(settings.init_lat),
            lng: Number(settings.init_lng),
            zoom: Number(settings.init_zoom) || 6
        };
    }

    /**
     * Apply the initial view. Returns true if it positioned the map, false to
     * let the caller fall back to auto-fitting the devices.
     */
    function applyInitialView() {
        var map = get_map(mapId);
        if (! map) { return false; }

        var view = null;

        if (settings.map_mode === 'remember') {
            view = storedView() || fixedView();
        } else if (settings.map_mode === 'fixed') {
            view = fixedView();
        }

        if (! view) { return false; }

        map.setView([view.lat, view.lng], view.zoom);
        return true;
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

        // Initial positioning happens once; after that only 'auto' keeps
        // re-fitting, so a refresh never yanks the map away from an operator
        // who has panned somewhere deliberately.
        if (! state.positioned) {
            state.positioned = true;
            // Spec section 18: when no explicit position is configured, fit the
            // devices actually present - never a hard-coded country or city.
            if (! applyInitialView() && state.payload.bounds) {
                fitDevices();
            }
        } else if (settings.map_mode === 'auto' && state.payload.bounds) {
            fitDevices();
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
        // Warnings published without geometry are selectable too now: there is
        // nothing to fly to, but their text is still worth reading.
        selectWarning($(this).attr('data-warning'));
        renderTable();
    });

    $root.on('click', '[data-locate-warning]', function (event) {
        event.stopPropagation();

        var map = get_map(mapId);
        var bounds = warningBounds($(this).attr('data-locate-warning'));
        if (map && bounds && bounds.isValid()) {
            map.fitBounds(bounds, { padding: [30, 30], maxZoom: 10 });
        }
    });

    $root.on('click', '[data-role="clear-selection"]', function () {
        state.selectedWarning = null;
        applyWarningEmphasis();      // also removes the halo
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
            $root.find('[data-role="legend"]').toggleClass('wx-hidden', ! state.showLegend);
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

    /*
     * Clicking a row expands the weather detail in place. It deliberately does
     * NOT move the map: reading a device's forecast should not cost you the
     * view you had set up. Use the crosshair button in the row to locate it.
     */
    $root.on('click', 'tbody tr[data-device]', function () {
        var $row = $(this);
        var id = $row.attr('data-device');

        // Clicking the open row again closes it.
        if (state.expandedDevice === id) {
            state.expandedDevice = null;
            $root.find('tr[data-detail]').remove();
            renderMarkers();
            return;
        }

        var device = null;
        (state.payload ? state.payload.devices : []).forEach(function (d) {
            if (String(d.device_id) === id) { device = d; }
        });
        if (! device) { return; }

        state.expandedDevice = id;
        $root.find('tr[data-detail]').remove();
        $row.after(detailRow(device));
        renderMarkers();          // highlight it on the map, without moving there
    });

    // Explicit locate: the only thing that moves the map from the table.
    $root.on('click', '[data-locate]', function (event) {
        event.stopPropagation();          // do not also toggle the detail row

        var marker = state.markers[$(this).attr('data-locate')];
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

                // In "remember" mode, persist wherever the operator settles.
                // moveend covers pan and zoom, and fires once per gesture.
                if (settings.map_mode === 'remember') {
                    map.on('moveend', storeView);
                }

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
                        state.haloLayer = null;
                        state.warningLayers = {};
                        destroy_map(mapId);
                    });
            });
        });
    });
})();
</script>
