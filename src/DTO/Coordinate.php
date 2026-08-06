<?php

namespace LibreNMS\Plugins\WeatherRiskMap\DTO;

/**
 * A validated WGS84 coordinate.
 *
 * Construction is deliberately private: use fromRaw(), which is the single
 * place where "is this coordinate usable" is decided. Anything that reaches
 * the rest of the system is therefore already known-good.
 */
final class Coordinate
{
    private function __construct(
        public readonly float $lat,
        public readonly float $lng,
    ) {}

    /**
     * Build from arbitrary DB input, rejecting anything unusable.
     *
     * Rejects null, non-numeric, out-of-range and the 0,0 "null island"
     * placeholder that LibreNMS itself treats as invalid
     * (see App\Models\Location::coordinatesValid()).
     */
    public static function fromRaw(mixed $lat, mixed $lng): ?self
    {
        if ($lat === null || $lng === null || $lat === '' || $lng === '') {
            return null;
        }

        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return null;
        }

        $lat = (float) $lat;
        $lng = (float) $lng;

        if (! is_finite($lat) || ! is_finite($lng)) {
            return null;
        }

        if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
            return null;
        }

        // 0,0 is a valid coordinate in open ocean but in practice always means
        // "unset" in a network inventory.
        if ($lat === 0.0 && $lng === 0.0) {
            return null;
        }

        return new self($lat, $lng);
    }

    /**
     * MET Norway's terms of service require coordinates truncated to at most
     * 4 decimals; more precision returns 403. Rounding here also collapses
     * near-identical sites onto the same upstream request.
     */
    public function forApi(): self
    {
        return new self(round($this->lat, 4), round($this->lng, 4));
    }

    public function key(int $precision = 4): string
    {
        return sprintf("%.{$precision}f,%.{$precision}f", $this->lat, $this->lng);
    }

    /** @return array{lat: float, lng: float} */
    public function toArray(): array
    {
        return ['lat' => $this->lat, 'lng' => $this->lng];
    }
}
