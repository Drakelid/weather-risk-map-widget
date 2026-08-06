<?php

namespace LibreNMS\Plugins\WeatherRiskMap\Enums;

/**
 * Infrastructure weather risk severity.
 *
 * The ordinal value is meaningful: risk is combined with max(), so the
 * backing integers must stay monotonic. Do not renumber them.
 *
 * These describe *weather* risk only. LibreNMS operational status (up/down)
 * is deliberately kept separate and is never folded into this scale.
 */
enum RiskLevel: int
{
    case Normal = 0;
    case Info = 1;
    case Moderate = 2;
    case High = 3;
    case Severe = 4;
    case Extreme = 5;

    /**
     * Marker/badge colour. Matches the palette required by the NOC spec:
     * green / blue / yellow / orange / red / purple.
     */
    public function color(): string
    {
        return match ($this) {
            self::Normal => 'green',
            self::Info => 'blue',
            self::Moderate => 'yellow',
            self::High => 'orange',
            self::Severe => 'red',
            self::Extreme => 'purple',
        };
    }

    /**
     * Hex colour used for map markers, polygons and table swatches.
     * Chosen to stay legible on both the light and dark LibreNMS themes.
     */
    public function hex(): string
    {
        return match ($this) {
            self::Normal => '#2e9e4f',
            self::Info => '#2f7ed8',
            self::Moderate => '#d9a406',
            self::High => '#e2711d',
            self::Severe => '#cc2b2b',
            self::Extreme => '#7b2ff7',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::Info => 'Info',
            self::Moderate => 'Moderate',
            self::High => 'High',
            self::Severe => 'Severe',
            self::Extreme => 'Extreme',
        };
    }

    public function emoji(): string
    {
        return match ($this) {
            self::Normal => '🟢',
            self::Info => '🔵',
            self::Moderate => '🟡',
            self::High => '🟠',
            self::Severe => '🔴',
            self::Extreme => '🟣',
        };
    }

    /** Highest of two levels. */
    public function max(self $other): self
    {
        return $this->value >= $other->value ? $this : $other;
    }

    public function atLeast(self $other): bool
    {
        return $this->value >= $other->value;
    }

    public static function fromValue(int $value): self
    {
        return self::tryFrom(max(0, min(5, $value))) ?? self::Normal;
    }
}
