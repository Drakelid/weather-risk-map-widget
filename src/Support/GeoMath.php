<?php

namespace LibreNMS\Plugins\WeatherRiskMap\Support;

use LibreNMS\Plugins\WeatherRiskMap\DTO\Coordinate;

final class GeoMath
{
    public const EARTH_RADIUS_KM = 6371.0088;

    /** Great-circle distance in kilometres. */
    public static function distanceKm(Coordinate $a, Coordinate $b): float
    {
        $latA = deg2rad($a->lat);
        $latB = deg2rad($b->lat);
        $dLat = $latB - $latA;
        $dLng = deg2rad($b->lng - $a->lng);

        $h = sin($dLat / 2) ** 2 + cos($latA) * cos($latB) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_KM * asin(min(1.0, sqrt($h)));
    }

    /** Degrees of latitude spanning the given distance. Constant everywhere. */
    public static function latDegreesForKm(float $km): float
    {
        return $km / 111.32;
    }

    /**
     * Degrees of longitude spanning the given distance at a given latitude.
     *
     * Meridians converge toward the poles, so a fixed degree step would make
     * grid cells far too wide in northern Norway. Clamped to avoid a division
     * blow-up at the poles.
     */
    public static function lngDegreesForKm(float $km, float $atLat): float
    {
        $scale = cos(deg2rad(max(-89.5, min(89.5, $atLat))));

        return $km / (111.32 * max(0.01, $scale));
    }
}
