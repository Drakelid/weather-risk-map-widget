<?php

namespace LibreNMS\Plugins\WeatherRiskMap\Tests\Unit;

use Carbon\CarbonImmutable;
use LibreNMS\Plugins\WeatherRiskMap\DTO\Coordinate;
use LibreNMS\Plugins\WeatherRiskMap\DTO\WeatherWarning;
use LibreNMS\Plugins\WeatherRiskMap\Enums\HazardType;
use LibreNMS\Plugins\WeatherRiskMap\Enums\RiskLevel;
use LibreNMS\Plugins\WeatherRiskMap\Services\WeatherGeoService;
use LibreNMS\Plugins\WeatherRiskMap\Support\GeoMath;
use LibreNMS\Plugins\WeatherRiskMap\Support\Polygon;
use PHPUnit\Framework\TestCase;

/**
 * Geographic grouping is the mechanism that keeps external request volume
 * independent of device count, so these tests guard the core scaling promise.
 */
final class WeatherGeoServiceTest extends TestCase
{
    public function test_devices_at_the_same_site_share_one_cell(): void
    {
        $geo = new WeatherGeoService(10.0);

        $grouped = $geo->groupIntoCells([
            1 => Coordinate::fromRaw(59.4138, 5.2680),
            2 => Coordinate::fromRaw(59.4138, 5.2680),
            3 => Coordinate::fromRaw(59.4139, 5.2681),
        ]);

        $this->assertCount(1, $grouped['cells'], 'one forecast request for the whole POP');
    }

    public function test_distant_sites_land_in_different_cells(): void
    {
        $geo = new WeatherGeoService(10.0);

        $grouped = $geo->groupIntoCells([
            1 => Coordinate::fromRaw(59.4138, 5.2680),   // Haugesund
            2 => Coordinate::fromRaw(60.3913, 5.3221),   // Bergen, ~110 km north
        ]);

        $this->assertCount(2, $grouped['cells']);
    }

    public function test_a_larger_radius_merges_more_sites(): void
    {
        $coordinates = [
            1 => Coordinate::fromRaw(59.40, 5.20),
            2 => Coordinate::fromRaw(59.45, 5.30),
            3 => Coordinate::fromRaw(59.50, 5.40),
        ];

        $fine = (new WeatherGeoService(1.0))->groupIntoCells($coordinates)['cells'];
        $coarse = (new WeatherGeoService(50.0))->groupIntoCells($coordinates)['cells'];

        $this->assertGreaterThan(count($coarse), count($fine));
    }

    public function test_membership_is_preserved_so_every_location_maps_back(): void
    {
        $geo = new WeatherGeoService(10.0);

        $grouped = $geo->groupIntoCells([
            10 => Coordinate::fromRaw(59.4138, 5.2680),
            20 => Coordinate::fromRaw(60.3913, 5.3221),
            30 => Coordinate::fromRaw(59.4140, 5.2682),
        ]);

        $mapped = [];
        foreach ($grouped['members'] as $ids) {
            foreach ($ids as $id) {
                $mapped[] = $id;
            }
        }

        sort($mapped);
        $this->assertSame([10, 20, 30], $mapped);
    }

    public function test_cell_centroids_are_api_safe(): void
    {
        $geo = new WeatherGeoService(10.0);

        $grouped = $geo->groupIntoCells([
            1 => Coordinate::fromRaw(59.413812345, 5.268012345),
            2 => Coordinate::fromRaw(59.413912345, 5.268112345),
        ]);

        foreach ($grouped['cells'] as $cell) {
            // MET Norway rejects more than 4 decimals with a 403.
            $this->assertSame(round($cell->lat, 4), $cell->lat);
            $this->assertSame(round($cell->lng, 4), $cell->lng);
        }
    }

    public function test_a_thousand_devices_collapse_to_few_cells(): void
    {
        // The scaling promise: device count must not drive request count.
        $coordinates = [];
        for ($i = 0; $i < 1000; $i++) {
            // 20 sites, 50 devices each.
            $site = $i % 20;
            $coordinates[$i] = Coordinate::fromRaw(59.0 + ($site * 0.5), 5.0 + ($site * 0.5));
        }

        $cells = (new WeatherGeoService(10.0))->groupIntoCells($coordinates)['cells'];

        $this->assertLessThanOrEqual(20, count($cells));
    }

    // ------------------------------------------------------------- warnings

    private function warningCovering(array $ring): WeatherWarning
    {
        return new WeatherWarning(
            id: 'w1', hazard: HazardType::Wind, risk: RiskLevel::High,
            severityLabel: 'Orange', title: 'Strong wind', description: null,
            instruction: null, consequences: null, area: 'Test',
            onset: null, expires: null,
            polygons: array_filter([Polygon::fromGeoJson([$ring])]),
            source: 'MET Norway', sourceUrl: null, fetchedAt: CarbonImmutable::now(),
        );
    }

    public function test_only_devices_inside_a_polygon_are_matched(): void
    {
        $geo = new WeatherGeoService(10.0);
        $warning = $this->warningCovering([[5.0, 59.0], [7.0, 59.0], [7.0, 61.0], [5.0, 61.0], [5.0, 59.0]]);

        $inside = $geo->warningsForPoint(Coordinate::fromRaw(60.0, 6.0), [$warning]);
        $outside = $geo->warningsForPoint(Coordinate::fromRaw(65.0, 15.0), [$warning]);

        $this->assertCount(1, $inside);
        $this->assertCount(0, $outside);
    }

    public function test_a_warning_without_geometry_matches_no_device(): void
    {
        $geo = new WeatherGeoService(10.0);

        $warning = new WeatherWarning(
            id: 'w2', hazard: HazardType::Wind, risk: RiskLevel::High,
            severityLabel: 'Orange', title: 'No geometry', description: null,
            instruction: null, consequences: null, area: null,
            onset: null, expires: null, polygons: [],
            source: 'MET Norway', sourceUrl: null, fetchedAt: CarbonImmutable::now(),
        );

        $this->assertCount(0, $geo->warningsForPoint(Coordinate::fromRaw(60.0, 6.0), [$warning]));
    }

    public function test_warnings_are_matched_per_cell_not_per_device(): void
    {
        $geo = new WeatherGeoService(10.0);
        $warning = $this->warningCovering([[5.0, 59.0], [7.0, 59.0], [7.0, 61.0], [5.0, 61.0], [5.0, 59.0]]);

        $cells = ['a' => Coordinate::fromRaw(60.0, 6.0), 'b' => Coordinate::fromRaw(65.0, 15.0)];
        $matches = $geo->matchWarningsToCells($cells, [$warning]);

        $this->assertCount(1, $matches['a']);
        $this->assertCount(0, $matches['b']);
    }

    // --------------------------------------------------------------- bounds

    public function test_bounds_cover_every_device(): void
    {
        $geo = new WeatherGeoService(10.0);

        $bounds = $geo->bounds([
            Coordinate::fromRaw(59.0, 5.0),
            Coordinate::fromRaw(61.0, 7.0),
            Coordinate::fromRaw(60.0, 6.0),
        ]);

        $this->assertSame(59.0, $bounds['south']);
        $this->assertSame(61.0, $bounds['north']);
        $this->assertSame(5.0, $bounds['west']);
        $this->assertSame(7.0, $bounds['east']);
    }

    public function test_bounds_of_a_single_device_are_a_point(): void
    {
        $bounds = (new WeatherGeoService(10.0))->bounds([Coordinate::fromRaw(59.4138, 5.2680)]);

        $this->assertSame($bounds['south'], $bounds['north']);
        $this->assertSame($bounds['west'], $bounds['east']);
    }

    public function test_bounds_of_nothing_is_null(): void
    {
        $this->assertNull((new WeatherGeoService(10.0))->bounds([]));
    }

    // ------------------------------------------------------------- geomath

    public function test_distance_is_approximately_correct(): void
    {
        // Haugesund to Bergen is roughly 110 km great-circle.
        $distance = GeoMath::distanceKm(
            Coordinate::fromRaw(59.4138, 5.2680),
            Coordinate::fromRaw(60.3913, 5.3221)
        );

        $this->assertGreaterThan(100.0, $distance);
        $this->assertLessThan(120.0, $distance);
    }

    public function test_longitude_degrees_widen_toward_the_pole(): void
    {
        $atEquator = GeoMath::lngDegreesForKm(10.0, 0.0);
        $atNorway = GeoMath::lngDegreesForKm(10.0, 70.0);

        $this->assertGreaterThan($atEquator, $atNorway);
    }
}
