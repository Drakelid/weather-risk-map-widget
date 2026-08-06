{{--
    Weather Risk Map widget shell.

    Renders immediately with no weather data: everything is fetched
    asynchronously from the data endpoint so a slow or dead weather provider
    cannot delay the LibreNMS dashboard.

    STYLING - deliberately no Tailwind.

    LibreNMS compiles Tailwind from its own tree only (resources/css/app.css has
    two @source directives plus the default project scan). This package lives
    outside that tree and is reached through a vendor symlink, so `tw:` classes
    used here are never generated. Everything below is plain, self-contained,
    .wxrisk-scoped CSS.

    THEMING - LibreNMS toggles class="dark" on <html> (librenms.js
    applySiteStyle), including at runtime for the "device" preference. Colours
    live in custom properties so one definition drives both this stylesheet and
    the JavaScript that paints markers and polygons.

    DENSITY - LibreNMS adds widgets at size_y 3 with cellHeight 100, i.e. about
    260px of usable body. The full chrome needs roughly twice that, so the root
    carries is-compact / is-tiny classes driven by the measured height and the
    layout sheds furniture rather than overflowing.

    TIME - every timestamp arrives as ISO and is formatted in the browser.
    Server-rendered clock strings would be in the application timezone while
    the browser renders others in the viewer's, putting two clocks in one view.
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
            <span class="wxrisk-spacer"></span>
            <span class="wxrisk-updated" data-role="updated"></span>
        </div>

        {{-- Degradation is a banner, not a tooltip: a NOC wall display cannot
             hover, and the reason matters more than the fact. --}}
        <div class="wxrisk-banner wx-hidden" data-role="banner" role="status" aria-live="polite"></div>

        <div class="wxrisk-chiprow" data-role="counters" role="group"
             aria-label="{{ __('weather-risk-map::widget.summary_heading') }}"></div>

        <div class="wxrisk-hazards">
            {{-- Compact layouts collapse the hazard chips behind this button. --}}
            <button type="button" class="wxrisk-hazbtn" data-role="hazard-menu"
                    aria-expanded="false">{{ __('weather-risk-map::widget.hazards_menu') }}</button>

            <div class="wxrisk-hazwrap" data-role="hazard-wrap">
                <div class="wxrisk-chiprow" data-role="hazard-toggles" role="group"
                     aria-label="{{ __('weather-risk-map::widget.settings.hazards') }}"></div>
                <div class="wxrisk-hazactions">
                    <button type="button" class="wxrisk-linkbtn" data-role="hazards-all">{{ __('weather-risk-map::widget.hazards_all') }}</button>
                    <button type="button" class="wxrisk-linkbtn" data-role="hazards-none">{{ __('weather-risk-map::widget.hazards_none') }}</button>
                </div>
            </div>
        </div>

        {{-- Active-filter bar: four independent filter states compose in ways
             nothing else in the UI explains. This makes them visible and
             individually dismissible. --}}
        <div class="wxrisk-filters wx-hidden" data-role="filters"></div>
    </div>

    {{-- Active warnings strip: what is warned, where, when, and how many
         devices sit inside it - without hunting for polygons. --}}
    <div class="wxrisk-warnings" data-role="warnings-wrap">
        <div class="wxrisk-warnings-head">
            <span class="wxrisk-subtitle">{{ __('weather-risk-map::widget.warnings_heading') }}</span>
            <span class="wxrisk-muted-text" data-role="warnings-count"></span>
            <span class="wxrisk-muted-text wx-hidden" data-role="warnings-more"></span>
        </div>
        <div class="wxrisk-stripwrap">
            <div class="wxrisk-strip" data-role="warning-cards"></div>
        </div>
    </div>

    {{-- ------------------------------------------------------------- map --}}
    <div class="wxrisk-map-wrap">
        <div id="{{ $wid }}-map" class="wxrisk-map"></div>

        <div class="wxrisk-toolbar" data-role="toolbar">
            <button type="button" data-action="fit-devices"
                    aria-label="{{ __('weather-risk-map::widget.fit_devices') }}"
                    title="{{ __('weather-risk-map::widget.fit_devices') }}"><svg class="wxrisk-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="7"/><path d="M12 1v4M12 19v4M1 12h4M19 12h4"/></svg></button>
            <button type="button" data-action="fit-warnings"
                    aria-label="{{ __('weather-risk-map::widget.fit_warnings') }}"
                    title="{{ __('weather-risk-map::widget.fit_warnings') }}"><svg class="wxrisk-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 2.5 20h19z"/><path d="M12 10v4M12 17h.01"/></svg></button>
            <button type="button" data-action="toggle-warnings" aria-pressed="true"
                    aria-label="{{ __('weather-risk-map::widget.toggle_warnings') }}"
                    title="{{ __('weather-risk-map::widget.toggle_warnings') }}"><svg class="wxrisk-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="M4 7l8-3 8 3v10l-8 3-8-3z"/><path d="M4 11h16M4 15h16"/></svg></button>
            <button type="button" data-action="toggle-legend" aria-pressed="false"
                    aria-label="{{ __('weather-risk-map::widget.legend') }}"
                    title="{{ __('weather-risk-map::widget.legend') }}"><svg class="wxrisk-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7.5h.01"/></svg></button>
        </div>

        <div class="wxrisk-legend wx-hidden" data-role="legend">
            <div class="wxrisk-legend-title">{{ __('weather-risk-map::widget.legend') }}</div>
            <div data-role="legend-items"></div>
            <div class="wxrisk-legend-row">
                <span class="wxrisk-legend-swatch wxrisk-marker is-down" style="background:var(--wx-normal)"></span>
                <span>{{ __('weather-risk-map::widget.legend_down') }}</span>
            </div>
            <div class="wxrisk-legend-hint">{{ __('weather-risk-map::widget.legend_hint') }}</div>
        </div>

        {{-- Shared detail panel for anything clicked on the map, warning
             area or device. It floats OVER the map: in the layout flow it stole
             height from the map pane (flex:1), which read as the map
             "minimising" on every selection. --}}
        <div class="wxrisk-wdetail wx-hidden" data-role="detail"></div>

        <div class="wxrisk-overlay" data-role="overlay">
            <span data-role="overlay-text">{{ __('weather-risk-map::widget.loading') }}</span>
        </div>
    </div>

    {{-- ---------------------------------------------- infrastructure table --}}
    <div class="wxrisk-tablehead">
        <button type="button" class="wxrisk-tabletoggle" data-role="table-toggle" aria-expanded="true">
            <svg class="wxrisk-ico wxrisk-caret" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
            <span class="wxrisk-subtitle">{{ __('weather-risk-map::widget.table_heading') }}</span>
        </button>
        <span class="wxrisk-muted-text" data-role="table-count"></span>
    </div>

    <div class="wxrisk-table-wrap" data-role="table-wrap">
        <table class="wxrisk-table">
            <thead>
                <tr>
                    <th data-sort="risk" scope="col" aria-sort="descending">{{ __('weather-risk-map::widget.table.risk') }}<span class="wxrisk-sortmark"></span></th>
                    <th data-sort="device" scope="col">{{ __('weather-risk-map::widget.table.device') }}<span class="wxrisk-sortmark"></span></th>
                    <th data-sort="location" scope="col">{{ __('weather-risk-map::widget.table.location') }}<span class="wxrisk-sortmark"></span></th>
                    <th data-sort="hazard" scope="col">{{ __('weather-risk-map::widget.table.hazard') }}<span class="wxrisk-sortmark"></span></th>
                    <th scope="col">{{ __('weather-risk-map::widget.table.peak') }}</th>
                    <th data-sort="time" scope="col">{{ __('weather-risk-map::widget.table.time') }}<span class="wxrisk-sortmark"></span></th>
                </tr>
            </thead>
            <tbody data-role="rows"></tbody>
        </table>
    </div>

    <div class="wxrisk-attribution" data-role="attribution"></div>
</div>

<style>
/* =====================================================================
 * Weather Risk Map - self-contained styles, scoped to .wxrisk-widget.
 * ===================================================================== */

/* ------------------------------------------------------------ tokens --- */
/*
 * Dark risk colours are lighter and less saturated than their light
 * counterparts: the #a8760a that reads well on white is muddy on a dimmed
 * dark basemap, and #c62222 at marker size is close to unreadable.
 *
 * Secondary text uses an explicit --wx-muted rather than opacity, which
 * compounds badly against a dark background.
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
    --wx-warn-bg:       #fdf3d4;
    --wx-warn-fg:       #6b4e00;
    --wx-warn-border:   #d9a406;
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
    --wx-warn-bg:       #4a3c12;
    --wx-warn-fg:       #ffe4a3;
    --wx-warn-border:   #d9a406;
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
.wxrisk-widget .wxrisk-tablehead,
.wxrisk-widget .wxrisk-table-wrap,
.wxrisk-widget .wxrisk-attribution { flex: 0 0 auto; }

.wxrisk-widget .wxrisk-headline {
    display: flex; flex-wrap: wrap; align-items: baseline; gap: 8px;
}
.wxrisk-widget .wxrisk-spacer { flex: 1 1 auto; }
.wxrisk-widget .wxrisk-title { font-size: 14px; font-weight: 700; letter-spacing: .02em; }
.wxrisk-widget .wxrisk-subtitle { font-size: 12.5px; font-weight: 600; }
.wxrisk-widget .wxrisk-window { font-size: 12.5px; color: var(--wx-muted); }
.wxrisk-widget .wxrisk-updated { font-size: 12px; color: var(--wx-muted); white-space: nowrap; }
.wxrisk-widget .wxrisk-updated.is-stale { color: var(--wx-warn-border); font-weight: 600; }
.wxrisk-widget .wxrisk-muted-text { font-size: 12px; color: var(--wx-muted); }
.wxrisk-widget .wx-dim { color: var(--wx-muted); }
.wxrisk-widget .wxrisk-chiprow { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 5px; }
.wxrisk-widget .wxrisk-attribution { margin-top: 4px; font-size: 11px; color: var(--wx-muted); }

/* Refresh feedback: a hairline bar rather than a blocking overlay. */
.wxrisk-widget .wxrisk-headline::after {
    content: ''; display: block; width: 100%; height: 2px; margin-top: 2px;
    background: linear-gradient(90deg, transparent, var(--wx-link), transparent);
    background-size: 40% 100%; background-repeat: no-repeat;
    opacity: 0; transition: opacity .15s;
}
.wxrisk-widget.is-loading .wxrisk-headline::after {
    opacity: .9; animation: wxrisk-sweep 1.1s linear infinite;
}
@keyframes wxrisk-sweep {
    from { background-position: -40% 0; }
    to   { background-position: 140% 0; }
}

/* ------------------------------------------------------------ banner --- */
.wxrisk-widget .wxrisk-banner {
    margin-top: 5px; padding: 5px 9px; border-radius: 3px;
    font-size: 12.5px; line-height: 1.45;
    background: var(--wx-warn-bg); color: var(--wx-warn-fg);
    border-left: 4px solid var(--wx-warn-border);
}

/* ------------------------------------------------------------- chips --- */
.wxrisk-widget .wxrisk-chip {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 9px; border-radius: 999px;
    font-size: 12.5px; line-height: 1.5; cursor: pointer;
    border: 1px solid transparent; user-select: none;
    background: var(--wx-surface); color: inherit; font-family: inherit;
    transition: background .12s, opacity .12s;
}
.wxrisk-widget .wxrisk-chip:hover { background: var(--wx-surface-hover); }
.wxrisk-widget .wxrisk-chip:focus-visible { outline: 2px solid var(--wx-link); outline-offset: 1px; }
.wxrisk-widget .wxrisk-chip.is-active { border-color: currentColor; font-weight: 700; }
.wxrisk-widget .wxrisk-chip.is-off { opacity: .45; text-decoration: line-through; }
.wxrisk-widget .wxrisk-chip b { font-size: 13px; }
.wxrisk-widget .wxrisk-chip .wxrisk-count-total { font-weight: 400; color: var(--wx-muted); }
.wxrisk-widget .wxrisk-dot {
    width: 10px; height: 10px; border-radius: 50%; flex: none;
    display: inline-block; vertical-align: -1px;
}

/* Inline SVG icons, sized in em so they track adjacent text. Replaced emoji,
   which rendered as tofu wherever the glyph was missing and could never match
   the theme palette. */
.wxrisk-widget .wxrisk-ico,
.wxrisk-popup .wxrisk-ico,
.leaflet-tooltip .wxrisk-ico {
    width: 1.05em; height: 1.05em; flex: none;
    display: inline-block; vertical-align: -0.16em;
}
.wxrisk-widget .wxrisk-toolbar button .wxrisk-ico { width: 16px; height: 16px; vertical-align: middle; }

.wxrisk-widget .wxrisk-badge {
    display: inline-block; padding: 1px 6px; border-radius: 3px;
    font-size: 10.5px; font-weight: 700; letter-spacing: .02em;
    background: var(--wx-warn-border); color: #1a1a1a;
}
/* LibreNMS operational status is a different axis from weather severity, so
   it gets its own colour rather than sharing the amber "official" badge. */
.wxrisk-widget .wxrisk-badge.is-down { background: var(--wx-severe); color: #fff; }
html.dark .wxrisk-widget .wxrisk-badge.is-down { color: #1a1a1a; }

.wxrisk-widget .wxrisk-linkbtn {
    background: none; border: 0; padding: 0;
    font-size: 12px; text-decoration: underline; cursor: pointer;
    color: var(--wx-link); font-family: inherit;
}
.wxrisk-widget .wxrisk-linkbtn:focus-visible { outline: 2px solid var(--wx-link); outline-offset: 2px; }

/* ------------------------------------------------------- hazard block --- */
.wxrisk-widget .wxrisk-hazards { position: relative; }
.wxrisk-widget .wxrisk-hazbtn {
    display: none; margin-top: 5px;
    font-size: 12.5px; font-family: inherit; cursor: pointer;
    padding: 3px 9px; border-radius: 999px;
    background: var(--wx-surface); color: inherit; border: 1px solid var(--wx-border);
}
.wxrisk-widget .wxrisk-hazbtn:hover { background: var(--wx-surface-hover); }
.wxrisk-widget .wxrisk-hazactions { display: flex; gap: 10px; margin-top: 4px; }

/* ------------------------------------------------------- filter bar ----- */
.wxrisk-widget .wxrisk-filters {
    display: flex; flex-wrap: wrap; align-items: center; gap: 5px;
    margin-top: 6px; padding: 4px 7px; border-radius: 3px;
    background: var(--wx-surface); border: 1px dashed var(--wx-border);
}
.wxrisk-widget .wxrisk-filters-label {
    font-size: 11.5px; font-weight: 700; color: var(--wx-muted);
    text-transform: uppercase; letter-spacing: .04em;
}
.wxrisk-widget .wxrisk-fpill {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 2px 4px 2px 8px; border-radius: 999px;
    font-size: 12px; background: var(--wx-panel); border: 1px solid var(--wx-border);
    max-width: 240px;
}
.wxrisk-widget .wxrisk-fpill span {
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.wxrisk-widget .wxrisk-fpill button {
    background: none; border: 0; cursor: pointer; padding: 0 3px;
    color: var(--wx-muted); font-size: 14px; line-height: 1; font-family: inherit;
}
.wxrisk-widget .wxrisk-fpill button:hover { color: var(--wx-severe); }

/* --------------------------------------------------- warnings strip ---- */
.wxrisk-widget .wxrisk-warnings { margin-top: 8px; }
.wxrisk-widget .wxrisk-warnings-head {
    display: flex; align-items: center; gap: 8px; margin-bottom: 4px;
}
/* Fade on the right edge when more cards exist off-screen; without it a
   horizontally scrolling strip looks like it simply ends. */
.wxrisk-widget .wxrisk-stripwrap { position: relative; }
.wxrisk-widget .wxrisk-stripwrap.has-overflow::after {
    content: ''; position: absolute; top: 0; right: 0; bottom: 3px; width: 34px;
    pointer-events: none;
    background: linear-gradient(90deg, transparent, var(--wx-scrim));
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
.wxrisk-widget .wxrisk-wcard:hover { background: var(--wx-surface-hover); transform: translateY(-1px); }
.wxrisk-widget .wxrisk-wcard:focus-visible { outline: 2px solid var(--wx-link); outline-offset: 1px; }
.wxrisk-widget .wxrisk-strip.has-selection .wxrisk-wcard { opacity: .5; }
.wxrisk-widget .wxrisk-strip.has-selection .wxrisk-wcard.is-selected {
    opacity: 1; box-shadow: 0 0 0 2px currentColor inset;
}
/* No map area published: readable, just not locatable. */
.wxrisk-widget .wxrisk-wcard.is-flat { border-left-style: dashed; }
.wxrisk-widget .wxrisk-wcard-top {
    display: flex; align-items: center; gap: 5px;
    font-size: 13px; font-weight: 600; line-height: 1.4;
}
.wxrisk-widget .wxrisk-wcard-name {
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--wx-fg);
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
.wxrisk-widget .wxrisk-nowarn { font-size: 12.5px; color: var(--wx-muted); padding: 2px 0; }

.wxrisk-widget .wxrisk-wdetail {
    position: absolute; left: 8px; bottom: 8px; z-index: 460;
    width: 340px; max-width: calc(100% - 16px);
    max-height: 64%; overflow-y: auto;
    padding: 8px 10px 10px;
    background: var(--wx-panel); color: var(--wx-panel-fg);
    border: 1px solid var(--wx-border);
    border-left: 4px solid currentColor;
    border-radius: 3px;
    box-shadow: 0 2px 12px rgba(0,0,0,.4);
}
.wxrisk-widget .wxrisk-wdetail-close {
    position: absolute; top: 4px; right: 6px;
    background: none; border: 0; cursor: pointer; padding: 2px 5px;
    font-size: 17px; line-height: 1; color: var(--wx-muted); font-family: inherit;
}
.wxrisk-widget .wxrisk-wdetail-close:hover { color: var(--wx-severe); }
.wxrisk-widget .wxrisk-wdetail .wxrisk-popup h4 { padding-right: 20px; }
.wxrisk-widget .wxrisk-wdetail .wxrisk-popup { max-width: none; min-width: 0; }
.wxrisk-widget .wxrisk-wdetail .wxrisk-popup .wxrisk-scroll { max-height: none; overflow: visible; }
.wxrisk-widget .wxrisk-wdetail .wxrisk-kv { max-width: 340px; }

/* --------------------------------------------------------------- map --- */
.wxrisk-widget .wxrisk-map-wrap {
    position: relative; flex: 1 1 auto; min-height: 210px;
    margin-top: 8px; border-radius: 4px; overflow: hidden;
    border: 1px solid var(--wx-border);
}
.wxrisk-widget .wxrisk-map { position: absolute; inset: 0; }
.wxrisk-widget .wxrisk-overlay {
    position: absolute; inset: 0; z-index: 500;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: 8px; text-align: center; padding: 0 14px;
    font-size: 13px; color: var(--wx-fg); background: var(--wx-scrim);
}

/* ------------------------------------------------------------- table --- */
.wxrisk-widget .wxrisk-tablehead {
    display: flex; align-items: center; gap: 8px; margin-top: 8px;
}
.wxrisk-widget .wxrisk-tabletoggle {
    display: inline-flex; align-items: center; gap: 5px;
    background: none; border: 0; padding: 0; cursor: pointer;
    color: inherit; font-family: inherit;
}
.wxrisk-widget .wxrisk-tabletoggle:focus-visible { outline: 2px solid var(--wx-link); outline-offset: 2px; }
.wxrisk-widget .wxrisk-caret { transition: transform .15s; }
.wxrisk-widget .wxrisk-tabletoggle[aria-expanded="false"] .wxrisk-caret { transform: rotate(-90deg); }

.wxrisk-widget .wxrisk-table-wrap {
    margin-top: 4px; max-height: 34%; overflow-y: auto;
    border: 1px solid var(--wx-border); border-radius: 4px;
}
.wxrisk-widget .wxrisk-table { width: 100%; font-size: 12.5px; border-collapse: collapse; }
.wxrisk-widget .wxrisk-table thead th {
    position: sticky; top: 0; z-index: 2;
    background: var(--wx-head-bg); color: var(--wx-fg);
    font-weight: 700; white-space: nowrap; text-align: left; padding: 5px 8px;
}
.wxrisk-widget .wxrisk-table th[data-sort] { cursor: pointer; }
.wxrisk-widget .wxrisk-sortmark { display: inline-block; width: 0; opacity: 0; margin-left: 3px; }
.wxrisk-widget .wxrisk-table th.is-sorted .wxrisk-sortmark { width: auto; opacity: .8; }
.wxrisk-widget .wxrisk-table tbody tr { cursor: pointer; border-top: 1px solid var(--wx-border); }
.wxrisk-widget .wxrisk-table tbody tr:hover { background: var(--wx-surface); }
.wxrisk-widget .wxrisk-table tbody tr:focus-visible { outline: 2px solid var(--wx-link); outline-offset: -2px; }
.wxrisk-widget .wxrisk-table td { padding: 4px 8px; vertical-align: top; }
.wxrisk-widget .wxrisk-table .wxrisk-empty { color: var(--wx-muted); padding: 8px; }
.wxrisk-widget .wxrisk-empty .wxrisk-linkbtn { margin-left: 8px; }
/* Risk is never colour-only: the level name rides with the swatch so the
   column survives a colour-vision deficiency and greyscale printing. */
.wxrisk-widget .wxrisk-risklabel { display: inline-flex; align-items: center; gap: 5px; white-space: nowrap; }

/* The selected row, mirroring the pulsing marker on the map. */
.wxrisk-widget .wxrisk-table tbody tr.is-selected {
    background: var(--wx-surface-hover);
    box-shadow: inset 3px 0 0 currentColor;
}

.wxrisk-widget .wxrisk-locate {
    background: none; border: 0; padding: 0 2px; margin-left: 3px;
    color: var(--wx-muted); cursor: pointer; vertical-align: -2px;
    opacity: 0; transition: opacity .12s, color .12s;
}
.wxrisk-widget .wxrisk-table tbody tr:hover .wxrisk-locate,
.wxrisk-widget .wxrisk-locate:focus-visible { opacity: 1; }
.wxrisk-widget .wxrisk-locate:hover { color: var(--wx-link); }
.wxrisk-widget .wxrisk-locate .wxrisk-ico { width: 13px; height: 13px; }

/* ----------------------------------------------------------- markers --- */
.wxrisk-marker { border-radius: 50%; border: 2px solid rgba(255,255,255,.9); box-sizing: border-box; }
html.dark .wxrisk-marker { border-color: rgba(255,255,255,.55); }
.wxrisk-marker.is-down { border-color: #111; border-width: 3px; border-style: double; }
html.dark .wxrisk-marker.is-down { border-color: #000; }
.wxrisk-marker.is-disabled { opacity: .45; }
.wxrisk-marker.is-severe { box-shadow: 0 0 0 3px color-mix(in srgb, var(--wx-severe) 40%, transparent), 0 0 6px rgba(0,0,0,.5); }
.wxrisk-marker.is-extreme { box-shadow: 0 0 0 3px color-mix(in srgb, var(--wx-extreme) 45%, transparent), 0 0 6px rgba(0,0,0,.5); }
.wxrisk-marker.is-highlit { box-shadow: 0 0 0 3px #fff, 0 0 0 5px #111; }
/* Selecting no longer moves the map, so the marker must be findable in place. */
.wxrisk-marker.is-selected {
    box-shadow: 0 0 0 3px #fff, 0 0 0 6px #111;
    animation: wxrisk-pulse 1.4s ease-out infinite;
}
html.dark .wxrisk-marker.is-selected { box-shadow: 0 0 0 3px #111, 0 0 0 6px #fff; }
.wxrisk-cluster.is-selected { animation: wxrisk-pulse 1.4s ease-out infinite; }

@keyframes wxrisk-pulse {
    0%   { outline: 0 solid rgba(255,255,255,.75); outline-offset: 0; }
    70%  { outline: 9px solid rgba(255,255,255,0); outline-offset: 2px; }
    100% { outline: 0 solid rgba(255,255,255,0); outline-offset: 0; }
}
.wxrisk-map .wxrisk-halo { animation: wxrisk-halo-pulse 1.8s ease-in-out infinite; }
@keyframes wxrisk-halo-pulse {
    0%, 100% { stroke-opacity: .15; }
    50%      { stroke-opacity: .55; }
}
@media (prefers-reduced-motion: reduce) {
    .wxrisk-marker.is-selected,
    .wxrisk-cluster.is-selected,
    .wxrisk-map .wxrisk-halo,
    .wxrisk-widget.is-loading .wxrisk-headline::after { animation: none; }
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
    border: 1px solid var(--wx-border); box-shadow: 0 1px 3px rgba(0,0,0,.35);
}
.wxrisk-widget .wxrisk-toolbar button:hover { background: var(--wx-surface-hover); }
.wxrisk-widget .wxrisk-toolbar button:focus-visible { outline: 2px solid var(--wx-link); outline-offset: 1px; }
.wxrisk-widget .wxrisk-toolbar button[aria-pressed="false"] { opacity: .5; }

.wxrisk-widget .wxrisk-legend {
    position: absolute; left: 8px; bottom: 8px; z-index: 450;
    background: var(--wx-panel); color: var(--wx-panel-fg);
    border: 1px solid var(--wx-border); border-radius: 3px;
    padding: 7px 9px; font-size: 12px; line-height: 1.65;
    box-shadow: 0 1px 4px rgba(0,0,0,.35); max-width: 230px;
}
.wxrisk-widget .wxrisk-legend-title { font-weight: 700; margin-bottom: 3px; font-size: 12.5px; }
.wxrisk-widget .wxrisk-legend-row { display: flex; align-items: center; gap: 6px; }
.wxrisk-widget .wxrisk-legend-swatch { width: 12px; height: 12px; border-radius: 50%; display: inline-block; flex: none; }
.wxrisk-widget .wxrisk-legend-hint { margin-top: 5px; font-size: 11px; line-height: 1.45; color: var(--wx-muted); }

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
html.dark .wxrisk-map .leaflet-control-attribution { background: rgba(20,23,26,.85); color: #c3c9d1; }
html.dark .wxrisk-map .leaflet-control-attribution a { color: #8cc2f0; }
.wxrisk-map .leaflet-tile-pane { filter: var(--wx-tile-filter); }

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
.wxrisk-popup .wxrisk-sec { margin-top: 7px; padding-top: 5px; border-top: 1px solid rgba(128,128,128,.4); }
.wxrisk-popup .wxrisk-muted { color: var(--wx-muted); }
html.dark .wxrisk-popup .wxrisk-muted { color: #b3bcc7; }
.wxrisk-popup .wxrisk-scroll { max-height: 170px; overflow-y: auto; }

/* ===================================================================== */
/* DENSITY                                                               */
/* A widget added at the LibreNMS default size has ~260px of body. Rather */
/* than overflow, shed furniture as height shrinks.                       */
/* ===================================================================== */
.wxrisk-widget.is-compact .wxrisk-hazbtn { display: inline-block; }
.wxrisk-widget.is-compact .wxrisk-hazwrap {
    display: none;
    position: absolute; z-index: 600; top: 100%; left: 0; margin-top: 4px;
    padding: 8px; border-radius: 4px; max-width: 300px;
    background: var(--wx-panel); color: var(--wx-panel-fg);
    border: 1px solid var(--wx-border); box-shadow: 0 2px 10px rgba(0,0,0,.35);
}
.wxrisk-widget.is-compact .wxrisk-hazards.is-open .wxrisk-hazwrap { display: block; }
.wxrisk-widget.is-compact .wxrisk-map-wrap { min-height: 130px; }
.wxrisk-widget.is-compact .wxrisk-wdetail { max-height: 120px; }
.wxrisk-widget.is-compact .wxrisk-attribution { display: none; }

.wxrisk-widget.is-tiny .wxrisk-warnings-head { margin-bottom: 2px; }
.wxrisk-widget.is-tiny .wxrisk-wcard-sub { display: none; }
.wxrisk-widget.is-tiny .wxrisk-map-wrap { min-height: 90px; margin-top: 5px; }
.wxrisk-widget.is-tiny .wxrisk-table-wrap { max-height: 46%; }
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
        expandedDevice: null,
        detail: null,           // {type: 'warning'|'device', id} - what the panel shows
        showWarnings: settings.show_warnings !== false,
        showLegend: false,
        tableOpen: true,
        tableUserSet: false,
        positioned: false,
        lastBoundsKey: null,
        loading: false,
        palette: null,
        themeObserver: null,
        sizeObserver: null,
        mapSizeObserver: null,
        wasDark: null
    };

    // `fallback` is only used before the stylesheet applies; the live colour
    // always comes from the CSS custom properties, so light and dark are
    // defined in exactly one place.
    var LEVELS = [
        { key: 'extreme',  value: 5, fallback: '#7326e8' },
        { key: 'severe',   value: 4, fallback: '#c62222' },
        { key: 'high',     value: 3, fallback: '#c9560d' },
        { key: 'moderate', value: 2, fallback: '#a8760a' },
        { key: 'info',     value: 1, fallback: '#2f7ed8' },
        { key: 'normal',   value: 0, fallback: '#1f8a45' }
    ];

    // ------------------------------------------------------------- helpers

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

    /** Laravel-style :placeholder substitution. */
    function tr(path, replacements, fallback) {
        var text = String(t(path, fallback !== undefined ? fallback : path));
        Object.keys(replacements || {}).forEach(function (key) {
            text = text.split(':' + key).join(replacements[key]);
        });
        return text;
    }

    /*
     * All times are formatted here, from ISO. The server deliberately sends no
     * pre-rendered clock strings: those would be in the application timezone
     * while these are in the viewer's, which put two different clocks in the
     * same table.
     */
    function fmtTime(iso) {
        if (! iso) { return null; }
        var d = new Date(iso);
        return isNaN(d.getTime()) ? null : d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    function fmtDateTime(iso) {
        if (! iso) { return null; }
        var d = new Date(iso);
        return isNaN(d.getTime()) ? null : d.toLocaleString();
    }

    function fmtPeriod(fromIso, toIso) {
        var a = fmtTime(fromIso);
        var b = fmtTime(toIso);
        if (! a && ! b) { return null; }
        return (a || '?') + '-' + (b || '?');
    }

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

    function levelMeta(value) {
        var base = LEVELS[LEVELS.length - 1];
        for (var i = 0; i < LEVELS.length; i++) {
            if (LEVELS[i].value === value) { base = LEVELS[i]; break; }
        }
        return {
            key: base.key,
            value: base.value,
            label: t('levels.' + base.key, base.key),
            color: (state.palette && state.palette[base.key]) || base.fallback
        };
    }

    /*
     * Inline SVG rather than emoji: the coloured-circle emoji are Emoji 12.0
     * and several hazard emoji default to text presentation, both of which
     * render as tofu. SVG also inherits currentColor, so indicators match the
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

    function riskDot(meta, px) {
        var size = px ? ';width:' + px + 'px;height:' + px + 'px' : '';
        return '<span class="wxrisk-dot" style="background:' + meta.color + size + '"></span>';
    }

    /** Risk swatch WITH its name: colour is never the only carrier. */
    function riskLabel(meta) {
        return '<span class="wxrisk-risklabel">' + riskDot(meta, 11) + esc(meta.label) + '</span>';
    }

    function overlay(text) {
        var $o = $root.find('[data-role="overlay"]');
        if (text === null) { $o.addClass('wx-hidden'); return; }
        $o.removeClass('wx-hidden').find('[data-role="overlay-text"]').text(text);
    }

    // -------------------------------------------------------------- filters

    function activeHazards(device) {
        return (device.hazards || []).filter(function (h) {
            return state.hazardFilter[h.hazard] !== false;
        });
    }

    /**
     * Risk recomputed against the hazards the operator is actually watching.
     *
     * The server scores every hazard enabled in the widget settings; switching
     * one off in the UI must lower a device whose risk that hazard was driving,
     * otherwise the counters, markers and table disagree with each other. The
     * two-severe escalation mirrors WeatherRiskService::overallRisk().
     */
    function effectiveRisk(device) {
        var hazards = activeHazards(device);
        if (! hazards.length) { return 0; }

        var worst = 0;
        var severeCount = 0;

        hazards.forEach(function (h) {
            worst = Math.max(worst, h.risk);
            if (h.risk >= 4) { severeCount++; }
        });

        return severeCount >= 2 ? 5 : worst;
    }

    function enabledHazardKeys() {
        var enabled = (state.payload && state.payload.settings && state.payload.settings.hazards)
                      || settings.hazards || {};
        return Object.keys(enabled).filter(function (k) { return enabled[k]; });
    }

    function hiddenHazardKeys() {
        return enabledHazardKeys().filter(function (k) { return state.hazardFilter[k] === false; });
    }

    function hasAnyFilter() {
        return state.severityFilter > 0
            || hiddenHazardKeys().length > 0
            || state.selectedWarning !== null
            || state.expandedDevice !== null;
    }

    function clearAllFilters() {
        state.severityFilter = 0;
        state.hazardFilter = {};
        state.selectedWarning = null;
        state.expandedDevice = null;
        applyWarningEmphasis();
        render();
    }

    /** Devices passing the hazard filter, before severity is applied. */
    function hazardFilteredDevices() {
        if (! state.payload) { return []; }
        return state.payload.devices.filter(function (d) {
            return effectiveRisk(d) === 0 ? true : activeHazards(d).length > 0;
        });
    }

    /** Devices actually drawn: hazard filter plus the severity floor. */
    function visibleDevices() {
        return hazardFilteredDevices().filter(function (device) {
            var risk = effectiveRisk(device);
            if (risk < state.severityFilter) { return false; }
            if (risk === 0) { return state.severityFilter === 0; }
            return true;
        });
    }

    // ------------------------------------------------------------- warnings

    function visibleWarnings() {
        if (! state.payload) { return []; }
        return (state.payload.warnings || []).filter(function (w) {
            return state.hazardFilter[w.hazard] !== false;
        });
    }

    function warningById(id) {
        var found = null;
        (state.payload ? state.payload.warnings : []).forEach(function (w) {
            if (w.id === id) { found = w; }
        });
        return found;
    }

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
        var $strip = $root.find('[data-role="warning-cards"]').empty();
        var $count = $root.find('[data-role="warnings-count"]');

        if (! warnings.length) {
            // An empty strip would read as "broken"; say it plainly instead.
            $count.text('');
            $strip.append($('<div/>', { 'class': 'wxrisk-nowarn', text: t('no_warnings') }));
            $root.find('[data-role="warnings-more"]').addClass('wx-hidden');
            $root.find('.wxrisk-stripwrap').removeClass('has-overflow');
            renderDetail();
            return;
        }

        // Worst and soonest first: what a NOC needs without scrolling.
        warnings = warnings.slice().sort(function (a, b) {
            if (b.risk !== a.risk) { return b.risk - a.risk; }
            var an = isActiveNow(a) ? 0 : 1, bn = isActiveNow(b) ? 0 : 1;
            if (an !== bn) { return an - bn; }
            return String(a.onset || '').localeCompare(String(b.onset || ''));
        });

        $count.text('(' + warnings.length + ')');

        warnings.forEach(function (warning) {
            var meta = levelMeta(warning.risk);
            var affected = affectedDeviceIds(warning.id).length;
            var hasGeometry = (warning.polygons || []).length > 0;
            var now = isActiveNow(warning);

            var affectedLabel = affected === 0
                ? t('no_devices_affected')
                : (affected === 1 ? t('one_device_affected') : tr('devices_affected', { count: affected }));

            var sub = [warning.area, fmtPeriod(warning.onset, warning.expires), affectedLabel]
                        .filter(Boolean).join(' · ');

            var selected = state.selectedWarning === warning.id;

            var $card = $('<div/>', {
                'class': 'wxrisk-wcard' + (hasGeometry ? '' : ' is-flat') + (selected ? ' is-selected' : ''),
                'style': 'color:' + meta.color,
                'data-warning': warning.id,
                'role': 'button',
                'tabindex': 0,
                'aria-pressed': selected ? 'true' : 'false',
                'title': hasGeometry ? '' : t('no_geometry')
            });

            $card.append(
                $('<div/>', { 'class': 'wxrisk-wcard-top' }).append(
                    $('<span/>', { 'class': 'wxrisk-when ' + (now ? 'is-now' : 'is-later'),
                                   text: now ? t('active_now') : t('upcoming') }),
                    $('<span/>', { html: hazardSvg(warning.hazard) }),
                    $('<span/>', { 'class': 'wxrisk-wcard-name', text: warning.event_name || warning.title })
                )
            );
            $card.append($('<div/>', { 'class': 'wxrisk-wcard-sub', text: sub }));

            $strip.append($card);
        });

        $strip.toggleClass('has-selection', state.selectedWarning !== null);
        renderDetail();
        updateStripOverflow();
    }

    /** Fade + count when cards continue past the right edge. */
    function updateStripOverflow() {
        var strip = $root.find('[data-role="warning-cards"]')[0];
        var $wrap = $root.find('.wxrisk-stripwrap');
        var $more = $root.find('[data-role="warnings-more"]');
        if (! strip) { return; }

        var overflowing = strip.scrollWidth > strip.clientWidth + 4;
        var atEnd = strip.scrollLeft + strip.clientWidth >= strip.scrollWidth - 4;

        $wrap.toggleClass('has-overflow', overflowing && ! atEnd);

        if (! overflowing) {
            $more.addClass('wx-hidden');
            return;
        }

        // Rough count of cards not fully in view - enough to signal "there is
        // more here" without pretending to be exact.
        var cards = strip.children.length;
        var perView = Math.max(1, Math.round(strip.clientWidth / 190));
        var hidden = Math.max(0, cards - perView);

        $more.toggleClass('wx-hidden', hidden === 0 || atEnd)
             .text(tr('more_warnings', { count: hidden }));
    }

    function warningBounds(warningId) {
        var bounds = null;

        Object.keys(state.warningLayers).forEach(function (id) {
            if (warningId && id !== warningId) { return; }
            state.warningLayers[id].forEach(function (layer) {
                var b = layer.getBounds();
                if (! b || ! b.isValid()) { return; }

                // Clone rather than seed from b: L.latLngBounds() returns the
                // SAME object when handed a bounds, so extending it would
                // mutate the polygon's own internal _bounds.
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
     * Select a warning to read it. Deliberately does NOT move the map: finding
     * out what a warning says should not cost the view you had set up. The map
     * changes emphasis only; the toolbar and the detail panel's button remain
     * for when going there is actually what you want.
     */
    /* The legend occupies the same corner as the detail panel. */
    function hideLegend() {
        if (! state.showLegend) { return; }
        state.showLegend = false;
        $root.find('[data-role="legend"]').addClass('wx-hidden');
        $root.find('[data-action="toggle-legend"]').attr('aria-pressed', 'false');
    }

    function selectWarning(warningId) {
        state.selectedWarning = (state.selectedWarning === warningId) ? null : warningId;
        state.detail = state.selectedWarning ? { type: 'warning', id: state.selectedWarning } : null;

        if (state.detail) { hideLegend(); }

        // Restyle the existing layers rather than rebuilding them: the click
        // that got us here is still being handled by one of them.
        applyWarningEmphasis();
        render({ rebuildPolygons: false });
    }

    /**
     * Devices open in the same overlay as warnings.
     *
     * Everything clicked on the map reports into one panel, so there is exactly
     * one place to look and never two cards describing the same thing.
     */
    function selectDevice(deviceId) {
        var id = String(deviceId);
        state.expandedDevice = (state.expandedDevice === id) ? null : id;
        state.detail = state.expandedDevice ? { type: 'device', id: state.expandedDevice } : null;

        if (state.detail) { hideLegend(); }
        render({ rebuildPolygons: false });
    }

    function closeDetail() {
        if (state.detail && state.detail.type === 'warning') {
            state.selectedWarning = null;
            applyWarningEmphasis();
        }
        if (state.detail && state.detail.type === 'device') {
            state.expandedDevice = null;
        }
        state.detail = null;
        render({ rebuildPolygons: false });
    }

    function renderDetail() {
        var $panel = $root.find('[data-role="detail"]');
        var detail = state.detail;

        var closeBtn = '<button type="button" class="wxrisk-wdetail-close" data-role="close-detail" '
                     + 'aria-label="' + esc(t('clear_selection')) + '">&times;</button>';

        if (detail && detail.type === 'warning') {
            var warning = warningById(detail.id);
            if (! warning) { $panel.addClass('wx-hidden').empty(); return; }

            var locatable = (warning.polygons || []).length > 0;

            $panel.removeClass('wx-hidden')
                .css('color', levelMeta(warning.risk).color)      // drives border-left
                .html(closeBtn + warningPopup(warning)
                    + (locatable
                        ? '<button type="button" class="wxrisk-linkbtn" data-locate-warning="'
                          + esc(warning.id) + '">' + esc(t('show_on_map')) + '</button>'
                        : '<span class="wxrisk-muted">' + esc(t('no_geometry')) + '</span>'));
            return;
        }

        if (detail && detail.type === 'device') {
            var device = deviceById(detail.id);
            if (! device) { $panel.addClass('wx-hidden').empty(); return; }

            $panel.removeClass('wx-hidden')
                .css('color', levelMeta(effectiveRisk(device)).color)
                .html(closeBtn + devicePopup(device)
                    + '<button type="button" class="wxrisk-linkbtn" data-locate="'
                    + device.device_id + '">' + esc(t('show_on_map')) + '</button>');
            return;
        }

        $panel.addClass('wx-hidden').empty();
    }

    /**
     * Thick pulsing outline around the selected warning. Dimming the others
     * says which one is selected but not where it is. A separate
     * non-interactive layer, so clicks, tooltips and the crisp boundary stroke
     * are all unaffected.
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
                color: colour, weight: 10, opacity: 0.35, fill: false,
                interactive: false, className: 'wxrisk-halo'
            });
        });

        state.haloLayer = L.layerGroup(halos).addTo(map);
        layers.forEach(function (layer) { if (layer.bringToFront) { layer.bringToFront(); } });
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

        // Counts are computed from the devices actually shown, not from the
        // server totals: with a hazard switched off the two disagree, and a
        // chip that contradicts the map destroys trust in both.
        var pool = hazardFilteredDevices();
        var counts = {};
        LEVELS.forEach(function (l) { counts[l.key] = 0; });
        pool.forEach(function (d) { counts[levelMeta(effectiveRisk(d)).key]++; });

        var filtered = hiddenHazardKeys().length > 0;

        var $counters = $root.find('[data-role="counters"]').empty();
        LEVELS.forEach(function (level) {
            var count = counts[level.key] || 0;
            var total = (p.counts && p.counts[level.key]) || 0;
            if (count === 0 && total === 0 && (level.key === 'extreme' || level.key === 'info')) { return; }

            var active = state.severityFilter === level.value && level.value > 0;
            var meta = levelMeta(level.value);

            $('<button/>', {
                'type': 'button',
                'class': 'wxrisk-chip' + (active ? ' is-active' : ''),
                'style': 'color:' + meta.color,
                'aria-pressed': active ? 'true' : 'false',
                'html': riskDot(meta) + '<b>' + count + '</b>'
                        + (filtered && total !== count
                            ? '<span class="wxrisk-count-total">/' + total + '</span>' : '')
                        + ' <span class="wx-dim">' + esc(meta.label) + '</span>',
                'data-level': level.value
            }).appendTo($counters);
        });

        renderStatus();

        $root.find('[data-role="attribution"]').text(
            (p.meta && p.meta.attribution ? p.meta.attribution : '')
            + (p.meta && p.meta.cells ? '  ·  ' + p.meta.cells + ' forecast areas / ' + p.meta.locations + ' sites' : '')
        );

        var $legend = $root.find('[data-role="legend-items"]').empty();
        LEVELS.slice().reverse().forEach(function (level) {
            var meta = levelMeta(level.value);
            $('<div/>', { 'class': 'wxrisk-legend-row' }).append(
                $('<span/>', { 'class': 'wxrisk-legend-swatch', style: 'background:' + meta.color }),
                $('<span/>', { text: meta.label })
            ).appendTo($legend);
        });
    }

    /**
     * Data age and degradation.
     *
     * Age is shown in normal operation too, not only when something breaks: an
     * operator needs to know at a glance whether they are looking at data from
     * four minutes ago or forty.
     */
    function renderStatus() {
        var meta = (state.payload && state.payload.meta) || {};
        var $updated = $root.find('[data-role="updated"]');
        var $banner = $root.find('[data-role="banner"]');

        var stamp = fmtTime(meta.last_success) || fmtTime(state.payload && state.payload.generated_at);
        var age = meta.oldest_data_minutes;

        if (stamp) {
            $updated.text(tr('updated_at', { time: stamp }))
                    .attr('title', fmtDateTime(meta.last_success || (state.payload && state.payload.generated_at)) || '')
                    .toggleClass('is-stale', typeof age === 'number' && age > 30);
        } else {
            $updated.text('').removeAttr('title').removeClass('is-stale');
        }

        var reason = meta.degraded_reason;
        if (! reason && ! meta.stale) { $banner.addClass('wx-hidden').empty(); return; }

        var parts = [];
        if (reason === 'forecast') { parts.push(t('degraded_forecast')); }
        else if (reason === 'warnings') { parts.push(t('degraded_warnings')); }
        else if (reason === 'both') { parts.push(t('degraded_both')); }
        else { parts.push(t('cached_notice')); }

        if (meta.last_success) { parts.push(tr('last_success', { time: fmtTime(meta.last_success) })); }
        if (typeof age === 'number') { parts.push(tr('cached_age', { minutes: age })); }
        parts.push(t('devices_still_shown'));

        $banner.removeClass('wx-hidden').text(parts.join(' '));
    }

    function renderHazardToggles() {
        var $wrap = $root.find('[data-role="hazard-toggles"]').empty();

        enabledHazardKeys().forEach(function (hazard) {
            var on = state.hazardFilter[hazard] !== false;

            // Worst level currently present for this hazard, so a chip carrying
            // real risk looks different from a quiet one.
            var worst = 0;
            (state.payload ? state.payload.devices : []).forEach(function (d) {
                (d.hazards || []).forEach(function (h) {
                    if (h.hazard === hazard) { worst = Math.max(worst, h.risk); }
                });
            });

            $('<button/>', {
                'type': 'button',
                'class': 'wxrisk-chip' + (on ? '' : ' is-off'),
                'data-hazard': hazard,
                'aria-pressed': on ? 'true' : 'false',
                'html': '<span class="wxrisk-dot" style="background:' + levelMeta(worst).color + '"></span>'
                        + esc(hazard.charAt(0).toUpperCase() + hazard.slice(1))
            }).appendTo($wrap);
        });
    }

    /** Everything currently narrowing the view, each individually removable. */
    function renderFilters() {
        var $bar = $root.find('[data-role="filters"]').empty();

        if (! hasAnyFilter()) { $bar.addClass('wx-hidden'); return; }
        $bar.removeClass('wx-hidden');

        $bar.append($('<span/>', { 'class': 'wxrisk-filters-label', text: t('filters_active') }));

        function pill(text, clear) {
            var $p = $('<span/>', { 'class': 'wxrisk-fpill' })
                .append($('<span/>', { text: text }))
                .append($('<button/>', { type: 'button', html: '&times;', 'aria-label': t('clear_all') }));
            $p.find('button').on('click', function (e) { e.stopPropagation(); clear(); });
            return $p;
        }

        if (state.severityFilter > 0) {
            $bar.append(pill(
                tr('filter_severity', { level: levelMeta(state.severityFilter).label }),
                function () { state.severityFilter = 0; render({ rebuildPolygons: false }); }
            ));
        }

        var hidden = hiddenHazardKeys();
        if (hidden.length) {
            var shown = enabledHazardKeys().filter(function (k) { return hidden.indexOf(k) === -1; });
            var text = shown.length > 0 && shown.length <= 3
                ? tr('filter_hazards_only', { list: shown.join(', ') })
                : tr('filter_hazards_hidden', { count: hidden.length });

            $bar.append(pill(text, function () { state.hazardFilter = {}; render(); }));
        }

        if (state.selectedWarning) {
            var w = warningById(state.selectedWarning);
            $bar.append(pill(
                tr('filter_warning', { name: w ? (w.event_name || w.title) : state.selectedWarning }),
                function () { state.selectedWarning = null; applyWarningEmphasis(); render({ rebuildPolygons: false }); }
            ));
        }

        if (state.expandedDevice) {
            var d = deviceById(state.expandedDevice);
            $bar.append(pill(
                tr('filter_device', { name: d ? d.display_name : state.expandedDevice }),
                function () { state.expandedDevice = null; render({ rebuildPolygons: false }); }
            ));
        }

        $bar.append($('<button/>', {
            type: 'button', 'class': 'wxrisk-linkbtn', 'data-role': 'clear-all', text: t('clear_all')
        }));
    }

    function deviceById(id) {
        var found = null;
        (state.payload ? state.payload.devices : []).forEach(function (d) {
            if (String(d.device_id) === String(id)) { found = d; }
        });
        return found;
    }

    /** Why the table came out empty - the reason is rarely the obvious one. */
    function emptyReason() {
        if (enabledHazardKeys().length && hiddenHazardKeys().length === enabledHazardKeys().length) {
            return t('empty_hazards');
        }
        if (state.selectedWarning) {
            var w = warningById(state.selectedWarning);
            if (w && (w.polygons || []).length) { return t('empty_warning'); }
        }
        if (hiddenHazardKeys().length) { return t('empty_hazard_filter'); }
        if (state.severityFilter > 0) { return t('empty_severity'); }
        return t('no_devices_at_risk');
    }

    function renderTable() {
        var $body = $root.find('[data-role="rows"]').empty();
        var visible = visibleDevices();
        var rows = visible.filter(function (d) { return effectiveRisk(d) >= 2; });

        // A locatable warning narrows the table to its devices, so strip, map
        // and table describe the same thing. A warning with no geometry matches
        // no device, so filtering on it would blank the table for no reason.
        if (state.selectedWarning) {
            var selected = warningById(state.selectedWarning);
            if (selected && (selected.polygons || []).length) {
                var ids = affectedDeviceIds(state.selectedWarning);
                rows = rows.filter(function (d) { return ids.indexOf(d.device_id) !== -1; });
            }
        }

        var total = state.payload ? state.payload.devices.length : 0;
        $root.find('[data-role="table-count"]').text(
            tr('table_heading_count', { shown: rows.length, total: total })
        );

        // Sort indicator: without it the header click is a coin flip.
        $root.find('.wxrisk-table th').each(function () {
            var $th = $(this);
            var key = $th.attr('data-sort');
            var active = key && key === state.sort.key;
            $th.toggleClass('is-sorted', !! active);
            $th.find('.wxrisk-sortmark').text(active ? (state.sort.dir === 'asc' ? '▲' : '▼') : '');
            if (key) { $th.attr('aria-sort', active ? (state.sort.dir === 'asc' ? 'ascending' : 'descending') : 'none'); }
        });

        if (! rows.length) {
            var $cell = $('<td/>', { colspan: 6, 'class': 'wxrisk-empty' })
                .append($('<span/>', { text: emptyReason() }));

            if (hasAnyFilter()) {
                $cell.append($('<button/>', {
                    type: 'button', 'class': 'wxrisk-linkbtn', 'data-role': 'clear-all', text: t('empty_clear')
                }));
            }

            $('<tr/>').append($cell).appendTo($body);
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
                default:         av = effectiveRisk(a); bv = effectiveRisk(b);
            }

            if (av < bv) { return -1 * dir; }
            if (av > bv) { return 1 * dir; }
            return (effectiveRisk(b) - effectiveRisk(a))
                || String(a.display_name).localeCompare(String(b.display_name));
        });

        rows.forEach(function (device) {
            var hazard = activeHazards(device)[0] || {};
            var meta = levelMeta(effectiveRisk(device));
            var expanded = state.expandedDevice === String(device.device_id);

            var $tr = $('<tr/>', {
                'class': expanded ? 'is-selected' : '',
                'data-device': device.device_id,
                'tabindex': 0,
                'role': 'button',
                'aria-pressed': expanded ? 'true' : 'false'
            });

            $tr.append($('<td/>', { html: riskLabel(meta) }));
            $tr.append($('<td/>', {
                html: esc(device.display_name)
                      + (device.up ? '' : ' <span class="wxrisk-badge is-down">' + esc(t('status_down')) + '</span>')
                      + ' <button type="button" class="wxrisk-locate" data-locate="' + device.device_id
                      + '" aria-label="' + esc(t('locate')) + '" title="' + esc(t('locate')) + '">'
                      + svgIcon(LOCATE_PATH) + '</button>'
            }));
            $tr.append($('<td/>', { text: device.location || '' }));
            $tr.append($('<td/>', {
                html: hazardSvg(hazard.hazard) + ' ' + esc(hazard.hazard_label || '')
                      + (hazard.official ? ' <span class="wxrisk-badge">OFFICIAL</span>' : '')
            }));
            $tr.append($('<td/>', { text: hazard.detail || '' }));
            $tr.append($('<td/>', { text: fmtTime(hazard.peak_time) || '' }));

            $body.append($tr);
        });
    }

    // ----------------------------------------------------------------- map

    function deviceIcon(device, highlit, selected) {
        var meta = levelMeta(effectiveRisk(device));
        var size = effectiveRisk(device) >= 3 ? 18 : 14;
        if (selected) { size += 4; }
        var classes = 'wxrisk-marker'
            + (device.up ? '' : ' is-down')
            + (device.disabled ? ' is-disabled' : '')
            + (effectiveRisk(device) === 4 ? ' is-severe' : '')
            + (effectiveRisk(device) === 5 ? ' is-extreme' : '')
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
        var meta = levelMeta(effectiveRisk(device));
        var html = '<div class="wxrisk-popup">';

        html += '<h4><a href="' + esc(deviceBase + '/' + device.device_id) + '">'
             + esc(device.display_name) + '</a></h4>';

        html += '<div class="wxrisk-kv"><span>' + esc(t('popup.status')) + '</span><b style="color:'
             + (device.up ? 'inherit' : meta.color) + '">'
             + esc(device.disabled ? t('status_disabled') : (device.up ? t('status_up') : t('status_down')))
             + '</b></div>';

        if (device.location) {
            html += '<div class="wxrisk-kv"><span>' + esc(t('popup.location')) + '</span><span>'
                 + esc(device.location) + '</span></div>';
        }

        html += '<div class="wxrisk-kv"><span>' + esc(t('popup.weather_risk')) + '</span><b>'
             + riskLabel(meta) + '</b></div>';

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
                var at = fmtTime(h.peak_time);
                html += '<div>' + riskDot(hm) + ' ' + esc(h.hazard_label) + ' — ' + esc(h.detail)
                     + (h.official ? ' <i class="wxrisk-muted">(official)</i>' : '')
                     + (h.inferred ? ' <i class="wxrisk-muted">(risk)</i>' : '')
                     + (at ? ' <span class="wxrisk-muted">@' + esc(at) + '</span>' : '')
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
                 + hazardSvg(warning.hazard) + ' ' + esc(warning.event_name || warning.title) + '</h4>';

        html += '<div class="wxrisk-kv"><span>' + esc(t('warning_popup.severity')) + '</span><b>'
             + riskLabel(meta) + ' <span class="wxrisk-muted">(' + esc(warning.severity_label) + ')</span></b></div>';

        if (warning.area) {
            html += '<div class="wxrisk-kv"><span>' + esc(t('warning_popup.area')) + '</span><span>'
                 + esc(warning.area) + '</span></div>';
        }
        html += '<div class="wxrisk-kv"><span>' + esc(t('table_heading')) + '</span><b>' + affected + '</b></div>';

        if (warning.onset) {
            html += '<div class="wxrisk-kv"><span>' + esc(t('warning_popup.starts')) + '</span><span>'
                 + esc(fmtDateTime(warning.onset)) + '</span></div>';
        }
        if (warning.expires) {
            html += '<div class="wxrisk-kv"><span>' + esc(t('warning_popup.ends')) + '</span><span>'
                 + esc(fmtDateTime(warning.expires)) + '</span></div>';
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
            html += ' · ' + esc(t('warning_popup.updated')) + ' ' + esc(fmtTime(warning.fetched_at));
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
            // Not-yet-active warnings are dashed and lighter, so "now" and
            // "later" are distinguishable on the map itself. Dark tiles are
            // dimmed, so the fill needs a little more presence.
            var baseFill = (now ? 0.22 : 0.10) + (isDark() ? 0.04 : 0);
            var wcolor = levelMeta(warning.risk).color;

            (warning.polygons || []).forEach(function (ring) {
                if (! ring || ring.length < 3) { return; }

                var polygon = L.polygon(ring, {
                    color: wcolor, weight: 2, opacity: 0.95,
                    dashArray: now ? null : '5,4',
                    fillColor: wcolor, fillOpacity: baseFill, _baseFill: baseFill
                });

                polygon.bindTooltip(
                    '<span style="color:' + wcolor + '">' + hazardSvg(warning.hazard) + '</span> '
                    + esc(warning.event_name || warning.title)
                    + (warning.area ? ' — ' + esc(warning.area) : ''),
                    { sticky: true, direction: 'top', className: 'wxrisk-wtip', opacity: 1 }
                );

                // Deliberately NO bindPopup here. The click selects the
                // warning and its text appears in the detail panel; a Leaflet
                // popup as well meant the same information opened twice, in two
                // places, from one click.

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
        if (! map || ! state.payload) { return; }

        var highlit = state.selectedWarning ? affectedDeviceIds(state.selectedWarning) : [];
        var devices = visibleDevices();
        state.markers = {};

        var markers = devices.map(function (device) {
            var isHighlit = highlit.indexOf(device.device_id) !== -1;
            var isSelected = state.expandedDevice === String(device.device_id);

            var marker = L.marker([device.lat, device.lng], {
                icon: deviceIcon(device, isHighlit, isSelected),
                title: device.display_name,
                zIndexOffset: isSelected ? 100000 : effectiveRisk(device) * 1000,
                // Carried explicitly rather than derived from zIndexOffset: the
                // cluster icon needs the real risk, and overloading the z-index
                // for selection would otherwise corrupt its colour.
                wxRisk: effectiveRisk(device),
                wxSelected: isSelected
            });
            // No bindPopup: a Leaflet popup here would be a second card
            // showing what the detail panel already shows, and it would cover
            // the map. Everything clicked reports into the one panel.
            marker.on('click', function () { selectDevice(device.device_id); });
            state.markers[device.device_id] = marker;
            return marker;
        });

        if (state.cluster) {
            state.cluster.clearLayers();
        } else if (state.payload.settings.clustering && L.markerClusterGroup) {
            state.cluster = L.markerClusterGroup({
                maxClusterRadius: 45,
                // The cluster takes the colour of its WORST device, so a single
                // red site is never hidden by 26 green ones.
                iconCreateFunction: function (cluster) {
                    var children = cluster.getAllChildMarkers();
                    var worst = 0;
                    var holdsSelected = false;
                    for (var i = 0; i < children.length; i++) {
                        worst = Math.max(worst, children[i].options.wxRisk || 0);
                        if (children[i].options.wxSelected) { holdsSelected = true; }
                    }
                    var meta = levelMeta(worst);
                    var size = worst >= 3 ? 40 : 34;
                    // A clustered selection would otherwise be invisible, so the
                    // cluster hiding it pulses in its place.
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

    /*
     * Where the map opens, per the widget's "Default map mode" setting:
     *   auto     - fit the visible devices on every refresh
     *   fixed    - always open at the configured lat/lng/zoom
     *   remember - restore wherever this operator last panned to
     *
     * "remember" persists to localStorage rather than the widget row on
     * purpose: it is a per-operator preference, several people commonly share
     * one dashboard, and writing widget settings needs dashboard update
     * permission a read-only NOC account will not have.
     */
    var VIEW_KEY = 'wxrisk.view.' + widgetId;

    function storedView() {
        try {
            var raw = window.localStorage.getItem(VIEW_KEY);
            if (! raw) { return null; }
            var view = JSON.parse(raw);
            if (typeof view.lat !== 'number' || typeof view.lng !== 'number'
                || typeof view.zoom !== 'number') { return null; }
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
            window.localStorage.setItem(VIEW_KEY, JSON.stringify({ lat: c.lat, lng: c.lng, zoom: map.getZoom() }));
        } catch (e) { /* localStorage unavailable - remembering just stops */ }
    }

    function fixedView() {
        if (settings.init_lat === null || settings.init_lat === undefined
            || settings.init_lng === null || settings.init_lng === undefined) { return null; }
        return {
            lat: Number(settings.init_lat),
            lng: Number(settings.init_lng),
            zoom: Number(settings.init_zoom) || 6
        };
    }

    function applyInitialView() {
        var map = get_map(mapId);
        if (! map) { return false; }

        var view = null;
        if (settings.map_mode === 'remember') { view = storedView() || fixedView(); }
        else if (settings.map_mode === 'fixed') { view = fixedView(); }

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

    function boundsKey() {
        var b = state.payload && state.payload.bounds;
        return b ? [b.south, b.west, b.north, b.east].join(',') : '';
    }

    /*
     * options.rebuildPolygons - recreate the warning layers
     * options.fit             - allow auto-fit to move the view
     *
     * Polygons are only rebuilt when something actually changed which warnings
     * are drawn. Rebuilding on selection would destroy the very layer the user
     * just clicked, out from under Leaflet's own click handling.
     *
     * NB: never write a JSDoc brace-type annotation in this file. Blade treats
     * a doubled opening brace as an echo and compiles whatever follows as PHP,
     * so such a comment takes the whole widget down with a parse error - even
     * inside a JavaScript comment, because Blade runs before the browser ever
     * sees it.
     */
    function renderMap(options) {
        if (options.rebuildPolygons) { renderPolygons(); }
        renderMarkers();

        if (! state.positioned) {
            state.positioned = true;
            state.lastBoundsKey = boundsKey();
            if (! applyInitialView() && state.payload.bounds) { fitDevices(); }
        } else if (options.fit && settings.map_mode === 'auto' && state.payload.bounds) {
            // Auto-fit follows the DEVICES, so it should only move when the
            // device set moves. Re-fitting on every render made selecting a
            // warning - or any filter click - snap the map back.
            var key = boundsKey();
            if (key !== state.lastBoundsKey) {
                state.lastBoundsKey = key;
                fitDevices();
            }
        }

        $root.find('[data-action="toggle-warnings"]').attr('aria-pressed', state.showWarnings ? 'true' : 'false');
    }

    // ------------------------------------------------------------- density

    /**
     * Shed furniture rather than overflow.
     *
     * LibreNMS adds widgets at size_y 3 / cellHeight 100, leaving about 260px
     * of body - roughly half what the full chrome needs. Rather than let the
     * widget scroll (which buries the map), the hazard chips fold into a
     * popover and the table collapses as height shrinks.
     */
    function applyDensity() {
        var h = $root.height() || 0;
        if (! h) { return; }

        var compact = h < 430;
        var tiny = h < 300;

        $root.toggleClass('is-compact', compact);
        $root.toggleClass('is-tiny', tiny);

        // Only auto-collapse until the operator expresses a preference.
        if (! state.tableUserSet) {
            setTableOpen(! compact, false);
        }
    }

    function setTableOpen(open, userInitiated) {
        state.tableOpen = open;
        if (userInitiated) { state.tableUserSet = true; }

        $root.find('[data-role="table-wrap"]').toggleClass('wx-hidden', ! open);
        $root.find('[data-role="table-toggle"]').attr('aria-expanded', open ? 'true' : 'false');
    }

    // -------------------------------------------------------------- render

    function render(options) {
        // Safe defaults: correct visuals, no map movement. Only the data load
        // asks to re-fit.
        options = $.extend({ rebuildPolygons: true, fit: false }, options || {});

        refreshPalette();
        renderSummary();
        renderHazardToggles();
        renderFilters();
        renderWarnings();
        renderDetail();
        renderTable();
        renderMap(options);
        applyDensity();

        var p = state.payload;
        overlay(p && p.devices.length === 0
            ? ((p.meta && p.meta.empty_reason) || t('no_devices'))
            : null);
    }

    function load() {
        if (state.loading) { return; }
        state.loading = true;
        $root.addClass('is-loading');

        $.ajax({
            type: 'POST',
            url: dataUrl,
            dataType: 'json',
            data: { id: widgetId, _token: csrfToken },
            success: function (data) {
                state.loading = false;
                $root.removeClass('is-loading');

                if (! data || data.status !== 'ok') {
                    overlay((data && data.message) || t('error_generic'));
                    return;
                }

                // Drop selections whose subject vanished between refreshes.
                if (state.selectedWarning
                    && ! (data.warnings || []).some(function (w) { return w.id === state.selectedWarning; })) {
                    state.selectedWarning = null;
                }
                if (state.expandedDevice
                    && ! (data.devices || []).some(function (d) { return String(d.device_id) === state.expandedDevice; })) {
                    state.expandedDevice = null;
                }
                if (state.detail
                    && ((state.detail.type === 'warning' && ! state.selectedWarning)
                        || (state.detail.type === 'device' && ! state.expandedDevice))) {
                    state.detail = null;
                }

                state.payload = data;
                render({ rebuildPolygons: true, fit: true });
            },
            error: function (xhr) {
                state.loading = false;
                $root.removeClass('is-loading');
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
        render({ rebuildPolygons: false });
    });

    $root.on('click', '[data-hazard]', function () {
        var hazard = $(this).attr('data-hazard');
        state.hazardFilter[hazard] = state.hazardFilter[hazard] === false;
        render();
    });

    $root.on('click', '[data-role="hazards-all"]', function () {
        state.hazardFilter = {};
        render();
    });

    $root.on('click', '[data-role="hazards-none"]', function () {
        enabledHazardKeys().forEach(function (k) { state.hazardFilter[k] = false; });
        render();
    });

    $root.on('click', '[data-role="hazard-menu"]', function () {
        var $wrap = $root.find('.wxrisk-hazards');
        var open = ! $wrap.hasClass('is-open');
        $wrap.toggleClass('is-open', open);
        $(this).attr('aria-expanded', open ? 'true' : 'false');
    });

    $root.on('click', '[data-role="clear-all"]', function (event) {
        event.stopPropagation();
        clearAllFilters();
    });

    $root.on('click', '[data-warning]', function () {
        // Warnings published without geometry are selectable too: there is
        // nothing to fly to, but their text is still worth reading.
        selectWarning($(this).attr('data-warning'));
    });

    $root.on('click', '[data-role="close-detail"]', function (event) {
        event.stopPropagation();
        closeDetail();
    });

    $root.on('click', '[data-locate-warning]', function (event) {
        event.stopPropagation();
        var map = get_map(mapId);
        var bounds = warningBounds($(this).attr('data-locate-warning'));
        if (map && bounds && bounds.isValid()) {
            map.fitBounds(bounds, { padding: [30, 30], maxZoom: 10 });
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

    $root.on('click', '[data-role="table-toggle"]', function () {
        setTableOpen(! state.tableOpen, true);
    });

    /*
     * Clicking a row expands the weather detail in place. It deliberately does
     * NOT move the map: reading a device's forecast should not cost the view
     * you had set up. The crosshair button in the row locates it explicitly.
     */
    $root.on('click', 'tbody tr[data-device]', function () {
        selectDevice($(this).attr('data-device'));
    });

    $root.on('click', '[data-locate]', function (event) {
        event.stopPropagation();          // do not also toggle the detail row

        var id = String($(this).attr('data-locate'));
        var marker = state.markers[id];
        var map = get_map(mapId);
        if (! marker || ! map) { return; }

        map.setView(marker.getLatLng(), Math.max(map.getZoom(), 11), { animate: true });

        if (state.cluster && state.cluster.zoomToShowLayer) {
            state.cluster.zoomToShowLayer(marker, function () {});
        }

        // Markers carry no popup any more, so show the panel explicitly.
        if (! state.detail || state.detail.type !== 'device' || state.detail.id !== id) {
            selectDevice(id);
        }
    });

    $root.on('click', '.wxrisk-toolbar button', function () {
        var action = $(this).attr('data-action');

        if (action === 'fit-devices') { fitDevices(); }
        if (action === 'fit-warnings') { fitWarnings(); }
        if (action === 'toggle-warnings') {
            state.showWarnings = ! state.showWarnings;
            $(this).attr('aria-pressed', state.showWarnings ? 'true' : 'false');
            renderPolygons();
        }
        if (action === 'toggle-legend') {
            state.showLegend = ! state.showLegend;
            $(this).attr('aria-pressed', state.showLegend ? 'true' : 'false');
            $root.find('[data-role="legend"]').toggleClass('wx-hidden', ! state.showLegend);
        }
    });

    // Keyboard equivalence for the elements that are not native buttons.
    $root.on('keydown', '[data-warning], tbody tr[data-device]', function (event) {
        if (event.key === 'Enter' || event.key === ' ' || event.key === 'Spacebar') {
            event.preventDefault();
            $(this).trigger('click');
        }
    });

    $root.on('scroll', '[data-role="warning-cards"]', updateStripOverflow);

    // ------------------------------------------------------------ theming

    /**
     * Repaint when the LibreNMS theme changes, without a dashboard reload.
     *
     * CSS follows html.dark on its own, but anything JavaScript painted does
     * not: Leaflet polygon strokes are inline SVG attributes and marker fills
     * are inline styles baked into a divIcon at creation, both frozen until
     * something rebuilds them.
     *
     * A MutationObserver is used rather than a prefers-color-scheme listener
     * because LibreNMS's explicit light/dark preferences override the OS; only
     * "device" follows it, and that path still toggles the class.
     */
    function watchTheme() {
        if (! window.MutationObserver) { return; }

        state.wasDark = isDark();

        state.themeObserver = new MutationObserver(function () {
            var dark = isDark();
            if (dark === state.wasDark) { return; }   // unrelated class change
            state.wasDark = dark;
            refreshPalette();
            if (state.payload) { render(); }
        });

        state.themeObserver.observe(document.documentElement, {
            attributes: true, attributeFilter: ['class']
        });
    }

    function watchSize() {
        if (! window.ResizeObserver) { return; }

        state.sizeObserver = new ResizeObserver(function () {
            applyDensity();
            updateStripOverflow();
        });
        state.sizeObserver.observe($root[0]);

        /*
         * The map pane is flex:1, so opening the warning detail panel or the
         * table shrinks it. Leaflet caches its container size and does not
         * notice, leaving tiles and markers drawn against stale dimensions -
         * which looks like the map jumping.
         *
         * pan:false is important: the default keeps the centre by panning,
         * which is itself a movement the operator did not ask for.
         */
        var pane = $root.find('.wxrisk-map-wrap')[0];
        if (! pane) { return; }

        state.mapSizeObserver = new ResizeObserver(function () {
            var map = get_map(mapId);
            if (map) { map.invalidateSize({ pan: false, debounceMoveend: true }); }
        });
        state.mapSizeObserver.observe(pane);
    }

    // ------------------------------------------------------------------ boot

    // The same load order the core world-map widget uses. L.Control.Locate is
    // required even though this widget never calls it: init_map() adds the
    // locate control itself whenever the page is served over HTTPS.
    loadjs('js/leaflet.js', function () {
        loadjs('js/leaflet.markercluster.js', function () {
            loadjs('js/L.Control.Locate.min.js', function () {
                var map = init_map(mapId, mapConfig);

                // Scroll-wheel zoom only while the pointer is engaged, matching
                // the core world-map widget so the dashboard still scrolls.
                map.scrollWheelZoom.disable();

                if (settings.map_mode === 'remember') {
                    map.on('moveend', storeView);
                }

                refreshPalette();
                watchTheme();
                watchSize();
                applyDensity();
                load();

                $('#' + rootId)
                    .on('click', function () { var m = get_map(mapId); if (m) { m.scrollWheelZoom.enable(); } })
                    .on('mouseleave', function () { var m = get_map(mapId); if (m) { m.scrollWheelZoom.disable(); } })
                    .on('resize', function () {
                        var m = get_map(mapId);
                        if (m) { m.invalidateSize({ pan: false }); }
                        applyDensity();
                        updateStripOverflow();
                    })
                    .on('refresh', function () {
                        var m = get_map(mapId);
                        if (m) { m.invalidateSize({ pan: false }); }
                        load();
                    })
                    .on('destroy', function () {
                        // Both observers outlive this widget's DOM, so they must
                        // be disconnected or they leak on every dashboard edit.
                        if (state.themeObserver) { state.themeObserver.disconnect(); state.themeObserver = null; }
                        if (state.sizeObserver) { state.sizeObserver.disconnect(); state.sizeObserver = null; }
                        if (state.mapSizeObserver) { state.mapSizeObserver.disconnect(); state.mapSizeObserver = null; }
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
