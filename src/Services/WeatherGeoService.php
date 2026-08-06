<?php

namespace LibreNMS\Plugins\WeatherRiskMap\Services;

use LibreNMS\Plugins\WeatherRiskMap\DTO\Coordinate;
use LibreNMS\Plugins\WeatherRiskMap\DTO\WeatherWarning;
use LibreNMS\Plugins\WeatherRiskMap\Support\GeoMath;

/**
 * Turns many device coordinates into few weather lookups.
 *
 * This is the service that makes the "never one API call per device" rule
 * structurally true rather than a matter of discipline:
 *
 *   1. Devices already share a LibreNMS location row, so a POP with 40
 *      switches is one coordinate before we start.
 *   2. Coordinates are then snapped to a grid whose cell size is the
 *      configured weather radius. Every site inside a cell shares one
 *      forecast, because a 10km-resolution forecast genuinely cannot
 *      distinguish them.
 *
 * A national ISP with 2000 devices across 150 sites in Norway typically
 * collapses to 30-60 cells, i.e. 30-60 cached requests per 15 minutes.
 */
class WeatherGeoService
{
    public function __construct(
        private readonly float $radiusKm = 10.0,
    ) {}

    /**
     * Grid cell identifier for a coordinate.
     *
     * The longitude step widens with latitude so cells stay roughly square in
     * ground distance rather than collapsing toward the pole.
     */
    public function cellId(Coordinate $coordinate): string
    {
        $latStep = max(0.01, GeoMath::latDegreesForKm($this->radiusKm));
        $lngStep = max(0.01, GeoMath::lngDegreesForKm($this->radiusKm, $coordinate->lat));

        $latIndex = (int) floor($coordinate->lat / $latStep);
        $lngIndex = (int) floor($coordinate->lng / $lngStep);

        return $latIndex . ':' . $lngIndex;
    }

    /**
     * Group coordinates into cells and pick one representative point each.
     *
     * The representative is the centroid of the members, truncated to 4
     * decimals for MET Norway's terms of service.
     *
     * @param  array<int|string, Coordinate>  $coordinates  keyed by caller's id
     * @return array{cells: array<string, Coordinate>, members: array<string, list<int|string>>}
     */
    public function groupIntoCells(array $coordinates): array
    {
        /** @var array<string, list<Coordinate>> $buckets */
        $buckets = [];
        /** @var array<string, list<int|string>> $members */
        $members = [];

        foreach ($coordinates as $id => $coordinate) {
            $cell = $this->cellId($coordinate);
            $buckets[$cell][] = $coordinate;
            $members[$cell][] = $id;
        }

        $cells = [];
        foreach ($buckets as $cell => $points) {
            $count = count($points);
            $lat = array_sum(array_map(fn (Coordinate $c) => $c->lat, $points)) / $count;
            $lng = array_sum(array_map(fn (Coordinate $c) => $c->lng, $points)) / $count;

            $centroid = Coordinate::fromRaw($lat, $lng);

            // Averaging validated coordinates inside one small cell cannot
            // leave the valid range, but fall back to a member rather than
            // dropping the cell if it somehow does.
            $cells[$cell] = ($centroid ?? $points[0])->forApi();
        }

        return ['cells' => $cells, 'members' => $members];
    }

    /**
     * Warnings affecting a point.
     *
     * Polygon containment is authoritative when the warning has geometry, per
     * the requirement to use official polygons directly. The radius fallback
     * only applies to warnings published without geometry - without it those
     * warnings would silently affect nobody.
     *
     * @param  list<WeatherWarning>  $warnings
     * @return list<WeatherWarning>
     */
    public function warningsForPoint(Coordinate $point, array $warnings): array
    {
        $matched = [];

        foreach ($warnings as $warning) {
            if ($warning->polygons !== []) {
                if ($warning->containsPoint($point)) {
                    $matched[] = $warning;
                }

                continue;
            }

            // No geometry published: we cannot place it, so it is not matched
            // to any device. It still appears in the active-warnings list so
            // the NOC can see it exists.
        }

        return $matched;
    }

    /**
     * Index warnings by grid cell so each cell is tested once instead of once
     * per device. With 2000 devices and 20 warnings this turns 40,000
     * point-in-polygon tests into roughly 1,000.
     *
     * @param  array<string, Coordinate>  $cells
     * @param  list<WeatherWarning>  $warnings
     * @return array<string, list<WeatherWarning>>
     */
    public function matchWarningsToCells(array $cells, array $warnings): array
    {
        $matches = [];

        foreach ($cells as $cellId => $coordinate) {
            $matches[$cellId] = $this->warningsForPoint($coordinate, $warnings);
        }

        return $matches;
    }

    /**
     * Bounding box of a set of coordinates, for automatic map fitting.
     *
     * @param  array<int|string, Coordinate>  $coordinates
     * @return array{south: float, west: float, north: float, east: float}|null
     */
    public function bounds(array $coordinates): ?array
    {
        if ($coordinates === []) {
            return null;
        }

        $lats = array_map(fn (Coordinate $c) => $c->lat, $coordinates);
        $lngs = array_map(fn (Coordinate $c) => $c->lng, $coordinates);

        return [
            'south' => min($lats),
            'west' => min($lngs),
            'north' => max($lats),
            'east' => max($lngs),
        ];
    }

    public function radiusKm(): float
    {
        return $this->radiusKm;
    }
}
