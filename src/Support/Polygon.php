<?php

namespace LibreNMS\Plugins\WeatherRiskMap\Support;

use LibreNMS\Plugins\WeatherRiskMap\DTO\Coordinate;

/**
 * A closed ring with optional holes, plus a cached bounding box.
 *
 * Warning polygons from MET Norway routinely carry several thousand vertices,
 * and every device is tested against every warning. The bounding-box check in
 * contains() rejects the overwhelming majority of pairs in constant time,
 * which is what keeps point-in-polygon matching viable at 1000+ devices.
 */
final class Polygon
{
    private float $minLat;
    private float $maxLat;
    private float $minLng;
    private float $maxLng;

    /**
     * @param  list<array{0: float, 1: float}>  $ring   outer ring as [lat, lng] pairs
     * @param  list<list<array{0: float, 1: float}>>  $holes  inner rings to exclude
     */
    public function __construct(
        private readonly array $ring,
        private readonly array $holes = [],
    ) {
        $lats = array_column($ring, 0);
        $lngs = array_column($ring, 1);

        // An empty ring can never contain anything; use an inverted box so the
        // bbox test always fails fast rather than dividing by nothing later.
        $this->minLat = $lats === [] ? 91.0 : (float) min($lats);
        $this->maxLat = $lats === [] ? -91.0 : (float) max($lats);
        $this->minLng = $lngs === [] ? 181.0 : (float) min($lngs);
        $this->maxLng = $lngs === [] ? -181.0 : (float) max($lngs);
    }

    /**
     * Build from a GeoJSON "Polygon" coordinate array.
     *
     * GeoJSON is [longitude, latitude] - the reverse of how coordinates are
     * written everywhere else in this package. The swap happens here, once.
     *
     * @param  array<int, mixed>  $coordinates
     */
    public static function fromGeoJson(array $coordinates): ?self
    {
        $rings = [];

        foreach ($coordinates as $ring) {
            if (! is_array($ring)) {
                continue;
            }

            $points = [];
            foreach ($ring as $pair) {
                if (! is_array($pair) || count($pair) < 2) {
                    continue;
                }
                if (! is_numeric($pair[0]) || ! is_numeric($pair[1])) {
                    continue;
                }
                $points[] = [(float) $pair[1], (float) $pair[0]];
            }

            // Fewer than 3 vertices cannot enclose an area.
            if (count($points) >= 3) {
                $rings[] = $points;
            }
        }

        if ($rings === []) {
            return null;
        }

        $outer = array_shift($rings);

        return new self($outer, $rings);
    }

    public function contains(Coordinate $point): bool
    {
        if ($point->lat < $this->minLat || $point->lat > $this->maxLat
            || $point->lng < $this->minLng || $point->lng > $this->maxLng) {
            return false;
        }

        if (! self::ringContains($this->ring, $point->lat, $point->lng)) {
            return false;
        }

        foreach ($this->holes as $hole) {
            if (self::ringContains($hole, $point->lat, $point->lng)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Standard ray-casting test.
     *
     * Warning areas are at most a few hundred km across, so treating lat/lng
     * as a plane is accurate to well under the resolution of the warning
     * itself. Antimeridian-spanning polygons are not handled; MET Norway does
     * not issue any, and a false negative there is a missing warning rather
     * than a wrong one.
     *
     * @param  list<array{0: float, 1: float}>  $ring
     */
    private static function ringContains(array $ring, float $lat, float $lng): bool
    {
        $inside = false;
        $count = count($ring);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            [$latI, $lngI] = $ring[$i];
            [$latJ, $lngJ] = $ring[$j];

            if (($lngI > $lng) !== ($lngJ > $lng)) {
                $denominator = $lngJ - $lngI;
                if ($denominator == 0.0) {
                    continue;
                }

                $intersectLat = ($latJ - $latI) * ($lng - $lngI) / $denominator + $latI;
                if ($lat < $intersectLat) {
                    $inside = ! $inside;
                }
            }
        }

        return $inside;
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} [minLat, minLng, maxLat, maxLng] */
    public function bounds(): array
    {
        return [$this->minLat, $this->minLng, $this->maxLat, $this->maxLng];
    }

    /**
     * Outer ring as GeoJSON-ordered [lng, lat] pairs for Leaflet rendering.
     *
     * @return list<array{0: float, 1: float}>
     */
    public function ringForDisplay(): array
    {
        return $this->ring;
    }

    public function vertexCount(): int
    {
        return count($this->ring);
    }

    /**
     * Douglas-Peucker style thinning for transmission to the browser.
     *
     * A national warning set can exceed several MB of raw geometry, which
     * makes the widget payload slow to transfer and slow for Leaflet to draw.
     * Display accuracy of ~1km is far finer than a weather warning boundary.
     *
     * @return list<array{0: float, 1: float}>
     */
    public function simplifiedRing(float $toleranceDegrees = 0.01): array
    {
        if (count($this->ring) <= 4) {
            return $this->ring;
        }

        $result = [$this->ring[0]];
        $last = $this->ring[0];

        foreach (array_slice($this->ring, 1, -1) as $point) {
            if (abs($point[0] - $last[0]) >= $toleranceDegrees
                || abs($point[1] - $last[1]) >= $toleranceDegrees) {
                $result[] = $point;
                $last = $point;
            }
        }

        $result[] = $this->ring[count($this->ring) - 1];

        // Thinning must never destroy the ring itself.
        return count($result) >= 3 ? $result : $this->ring;
    }
}
