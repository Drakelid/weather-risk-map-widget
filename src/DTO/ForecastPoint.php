<?php

namespace LibreNMS\Plugins\WeatherRiskMap\DTO;

use Carbon\CarbonImmutable;

/**
 * One forecast hour at one point.
 *
 * Every field is nullable on purpose: providers legitimately omit variables
 * (MET Norway drops next_1_hours near the end of the series, and gust speed
 * is absent from the "compact" product at some lead times). The risk engine
 * must treat null as "unknown", never as zero.
 */
final class ForecastPoint
{
    public function __construct(
        public readonly CarbonImmutable $time,
        public readonly ?float $temperature = null,      // deg C
        public readonly ?float $windSpeed = null,        // m/s
        public readonly ?float $windGust = null,         // m/s
        public readonly ?float $precipitation = null,    // mm in the covered hour
        public readonly ?float $humidity = null,         // %
        public readonly ?string $symbolCode = null,      // provider symbol, e.g. "heavyrainandthunder"
        public readonly ?float $probabilityOfThunder = null, // %
    ) {}

    /**
     * MET Norway encodes thunder in the symbol code suffix ("...andthunder"),
     * and there is no separate boolean in the compact product.
     */
    public function symbolIndicatesThunder(): bool
    {
        return $this->symbolCode !== null && str_contains($this->symbolCode, 'thunder');
    }

    public function symbolIndicatesSnow(): bool
    {
        return $this->symbolCode !== null && str_contains($this->symbolCode, 'snow');
    }

    public function symbolIndicatesSleet(): bool
    {
        return $this->symbolCode !== null && str_contains($this->symbolCode, 'sleet');
    }

    /** Highest of measured wind and gust, ignoring unknowns. */
    public function effectiveWind(): ?float
    {
        $values = array_filter([$this->windSpeed, $this->windGust], fn ($v) => $v !== null);

        return $values === [] ? null : max($values);
    }
}
