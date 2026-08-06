<?php

namespace LibreNMS\Plugins\WeatherRiskMap\DTO;

use LibreNMS\Plugins\WeatherRiskMap\Enums\HazardType;

/**
 * Validated, normalised widget settings.
 *
 * Widget settings are user-supplied and stored as JSON on `users_widgets`, so
 * they are untrusted input on every read - not just on save. Everything is
 * coerced and range-checked here so the rest of the package can treat these
 * values as safe, and so a hand-edited or legacy row cannot inject anything
 * into a query, a URL or the rendered page.
 */
final class WidgetSettings
{
    public const ALLOWED_PERIODS = [0, 3, 6, 12, 24, 48];

    public const ALLOWED_RADII = [1, 5, 10, 25, 50];

    public const ALLOWED_REFRESH = [300, 600, 900, 1800, 3600];

    /**
     * @param  list<int>  $deviceGroups
     * @param  array<string, bool>  $hazards
     * @param  array<string, mixed>  $thresholds
     */
    private function __construct(
        public readonly ?string $title,
        public readonly array $deviceGroups,
        public readonly int $refresh,
        public readonly int $riskPeriod,
        public readonly int $minSeverity,
        public readonly int $weatherRadiusKm,
        public readonly bool $clustering,
        public readonly bool $showWarnings,
        public readonly string $mapMode,
        public readonly array $hazards,
        public readonly array $thresholds,
    ) {}

    /**
     * @param  array<string, mixed>  $raw  values straight from the widget row / request
     * @param  array<string, mixed>  $config  installation defaults
     */
    public static function fromArray(array $raw, array $config): self
    {
        $defaults = $config['defaults'] ?? [];
        $raw = self::expandFlatKeys($raw);

        return new self(
            title: self::cleanTitle($raw['title'] ?? null),
            deviceGroups: self::intList($raw['device_groups'] ?? $raw['device_group'] ?? []),
            refresh: self::oneOf((int) ($raw['refresh'] ?? $defaults['refresh'] ?? 600), self::ALLOWED_REFRESH, 600),
            riskPeriod: self::oneOf((int) ($raw['risk_period'] ?? $defaults['risk_period'] ?? 24), self::ALLOWED_PERIODS, 24),
            minSeverity: max(0, min(5, (int) ($raw['min_severity'] ?? $defaults['min_severity'] ?? 0))),
            weatherRadiusKm: self::oneOf((int) ($raw['weather_radius_km'] ?? $defaults['weather_radius_km'] ?? 10), self::ALLOWED_RADII, 10),
            clustering: self::bool($raw['clustering'] ?? $defaults['clustering'] ?? true),
            showWarnings: self::bool($raw['show_warnings'] ?? $defaults['show_warnings'] ?? true),
            mapMode: in_array($raw['map_mode'] ?? null, ['auto', 'remember'], true) ? $raw['map_mode'] : ($defaults['map_mode'] ?? 'auto'),
            hazards: self::hazards($raw['hazards'] ?? null),
            thresholds: self::thresholds($raw['thresholds'] ?? null, $config['thresholds'] ?? []),
        );
    }

    /**
     * Shape consumed by WeatherRiskMapBuilder.
     *
     * @return array<string, mixed>
     */
    public function toBuilderSettings(): array
    {
        return [
            'device_groups' => $this->deviceGroups,
            'risk_period' => $this->riskPeriod,
            'show_warnings' => $this->showWarnings,
            'hazards' => $this->hazards,
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'device_groups' => $this->deviceGroups,
            'refresh' => $this->refresh,
            'risk_period' => $this->riskPeriod,
            'min_severity' => $this->minSeverity,
            'weather_radius_km' => $this->weatherRadiusKm,
            'clustering' => $this->clustering,
            'show_warnings' => $this->showWarnings,
            'map_mode' => $this->mapMode,
            'hazards' => $this->hazards,
            'thresholds' => $this->thresholds,
        ];
    }

    /**
     * Rebuild nested settings from the flat keys the settings form submits.
     *
     * LibreNMS's widget_settings() JS flattens the form with serializeArray()
     * and only understands names ending in `[]`; a name like `hazards[wind]`
     * would be stored under that literal string. Rather than depend on that
     * behaviour, the form posts flat `hazard_*` / `threshold_*` names and they
     * are expanded here.
     *
     * Threshold names are split on the LAST underscore because hazard keys
     * themselves contain underscores (`wind_gust`, `rain_hourly`), while the
     * level suffix never does.
     *
     * Already-nested input is passed through untouched, so a directly edited
     * database row or a future core change keeps working.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private static function expandFlatKeys(array $raw): array
    {
        $levels = ['moderate', 'high', 'severe', 'extreme', 'info'];

        foreach ($raw as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            if (str_starts_with($key, 'hazard_')) {
                $hazard = substr($key, strlen('hazard_'));
                if ($hazard !== '') {
                    $raw['hazards'] = is_array($raw['hazards'] ?? null) ? $raw['hazards'] : [];
                    $raw['hazards'][$hazard] = $value;
                }

                continue;
            }

            if (str_starts_with($key, 'threshold_')) {
                $rest = substr($key, strlen('threshold_'));
                $split = strrpos($rest, '_');
                if ($split === false) {
                    continue;
                }

                $group = substr($rest, 0, $split);
                $level = substr($rest, $split + 1);

                if ($group === '' || ! in_array($level, $levels, true)) {
                    continue;
                }

                // An emptied field means "use the installation default", which
                // is expressed by simply not overriding it.
                if ($value === '' || $value === null) {
                    continue;
                }

                $raw['thresholds'] = is_array($raw['thresholds'] ?? null) ? $raw['thresholds'] : [];
                $raw['thresholds'][$group] = is_array($raw['thresholds'][$group] ?? null)
                    ? $raw['thresholds'][$group]
                    : [];
                $raw['thresholds'][$group][$level] = $value;
            }
        }

        return $raw;
    }

    private static function cleanTitle(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        // The title is rendered into the widget header; strip markup at the
        // boundary rather than relying on every consumer to escape it.
        $clean = trim(strip_tags($value));

        return $clean === '' ? null : mb_substr($clean, 0, 120);
    }

    /** @return list<int> */
    private static function intList(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        // Accepts an array (multi-select), a CSV string (legacy/manual edit),
        // or a single id (upgraded single-group widget).
        $items = is_array($value) ? $value : explode(',', (string) $value);

        $ids = [];
        foreach ($items as $item) {
            if (is_numeric($item) && (int) $item > 0) {
                $ids[] = (int) $item;
            }
        }

        return array_values(array_unique($ids));
    }

    /** @param list<int> $allowed */
    private static function oneOf(int $value, array $allowed, int $fallback): int
    {
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private static function bool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }

    /** @return array<string, bool> */
    private static function hazards(mixed $raw): array
    {
        $out = [];
        $raw = is_array($raw) ? $raw : [];

        foreach (HazardType::toggleable() as $hazard) {
            // Absent means enabled: a widget saved before a hazard existed
            // should start watching it rather than silently ignoring it.
            $out[$hazard->value] = ! array_key_exists($hazard->value, $raw)
                || self::bool($raw[$hazard->value]);
        }

        return $out;
    }

    /**
     * Merge user threshold overrides over the installation defaults.
     *
     * Only keys that already exist in the defaults are accepted, and only
     * numeric values, so a malformed row can neither introduce unknown keys
     * nor make the risk engine compare against a string.
     *
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    private static function thresholds(mixed $raw, array $defaults): array
    {
        if (! is_array($raw)) {
            return $defaults;
        }

        $merged = $defaults;

        foreach ($raw as $hazard => $levels) {
            if (! array_key_exists($hazard, $defaults)) {
                continue;
            }

            if (is_numeric($levels)) {
                $merged[$hazard] = (float) $levels;

                continue;
            }

            if (! is_array($levels) || ! is_array($defaults[$hazard])) {
                continue;
            }

            foreach ($levels as $level => $value) {
                if (array_key_exists($level, $defaults[$hazard]) && is_numeric($value)) {
                    $merged[$hazard][$level] = (float) $value;
                }
            }
        }

        return $merged;
    }
}
