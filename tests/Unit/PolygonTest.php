<?php

namespace LibreNMS\Plugins\WeatherRiskMap\Tests\Unit;

use LibreNMS\Plugins\WeatherRiskMap\DTO\Coordinate;
use LibreNMS\Plugins\WeatherRiskMap\Support\Polygon;
use PHPUnit\Framework\TestCase;

/**
 * Point-in-polygon matching is what replaces per-device API calls, so its
 * correctness is load-bearing for the whole architecture.
 */
final class PolygonTest extends TestCase
{
    /** A 2x2 degree square around 59-61 N, 5-7 E. */
    private function square(): Polygon
    {
        // GeoJSON order is [lng, lat].
        return Polygon::fromGeoJson([[
            [5.0, 59.0],
            [7.0, 59.0],
            [7.0, 61.0],
            [5.0, 61.0],
            [5.0, 59.0],
        ]]);
    }

    public function test_it_parses_geojson_lng_lat_order_correctly(): void
    {
        $polygon = $this->square();

        $this->assertNotNull($polygon);
        [$minLat, $minLng, $maxLat, $maxLng] = $polygon->bounds();

        $this->assertSame(59.0, $minLat);
        $this->assertSame(61.0, $maxLat);
        $this->assertSame(5.0, $minLng);
        $this->assertSame(7.0, $maxLng);
    }

    public function test_a_point_inside_is_matched(): void
    {
        $this->assertTrue($this->square()->contains(Coordinate::fromRaw(60.0, 6.0)));
    }

    public function test_points_outside_are_not_matched(): void
    {
        $polygon = $this->square();

        $this->assertFalse($polygon->contains(Coordinate::fromRaw(62.0, 6.0)), 'north of the box');
        $this->assertFalse($polygon->contains(Coordinate::fromRaw(58.0, 6.0)), 'south of the box');
        $this->assertFalse($polygon->contains(Coordinate::fromRaw(60.0, 4.0)), 'west of the box');
        $this->assertFalse($polygon->contains(Coordinate::fromRaw(60.0, 8.0)), 'east of the box');
    }

    public function test_a_hole_excludes_points_inside_it(): void
    {
        // Outer square with an inner square cut out of the middle.
        $polygon = Polygon::fromGeoJson([
            [[5.0, 59.0], [7.0, 59.0], [7.0, 61.0], [5.0, 61.0], [5.0, 59.0]],
            [[5.8, 59.8], [6.2, 59.8], [6.2, 60.2], [5.8, 60.2], [5.8, 59.8]],
        ]);

        $this->assertNotNull($polygon);
        $this->assertFalse($polygon->contains(Coordinate::fromRaw(60.0, 6.0)), 'inside the hole');
        $this->assertTrue($polygon->contains(Coordinate::fromRaw(59.2, 5.2)), 'outside the hole');
    }

    public function test_a_concave_polygon_is_handled(): void
    {
        // An L shape; the notch must not be counted as inside.
        $polygon = Polygon::fromGeoJson([[
            [0.0, 0.0], [4.0, 0.0], [4.0, 1.0], [1.0, 1.0], [1.0, 4.0], [0.0, 4.0], [0.0, 0.0],
        ]]);

        $this->assertNotNull($polygon);
        $this->assertTrue($polygon->contains(Coordinate::fromRaw(0.5, 0.5)), 'in the corner');
        $this->assertTrue($polygon->contains(Coordinate::fromRaw(0.5, 3.0)), 'in the vertical arm');
        $this->assertFalse($polygon->contains(Coordinate::fromRaw(3.0, 3.0)), 'in the notch');
    }

    public function test_malformed_geometry_is_rejected_rather_than_throwing(): void
    {
        $this->assertNull(Polygon::fromGeoJson([]), 'empty');
        $this->assertNull(Polygon::fromGeoJson([[[5.0, 59.0], [7.0, 59.0]]]), 'only two vertices');
        $this->assertNull(Polygon::fromGeoJson([['nonsense']]), 'not coordinate pairs');
        $this->assertNull(Polygon::fromGeoJson([[['a', 'b'], ['c', 'd'], ['e', 'f']]]), 'non-numeric');
    }

    public function test_it_skips_bad_vertices_but_keeps_a_usable_ring(): void
    {
        $polygon = Polygon::fromGeoJson([[
            [5.0, 59.0],
            ['bad'],
            [7.0, 59.0],
            [7.0, 61.0],
            [5.0, 61.0],
        ]]);

        $this->assertNotNull($polygon);
        $this->assertSame(4, $polygon->vertexCount());
    }

    public function test_simplification_preserves_a_drawable_ring(): void
    {
        $ring = [];
        for ($i = 0; $i < 400; $i++) {
            $ring[] = [5.0 + ($i * 0.0001), 59.0 + ($i * 0.0001)];
        }
        $ring[] = [5.0, 59.0];

        $polygon = Polygon::fromGeoJson([$ring]);
        $this->assertNotNull($polygon);

        $simplified = $polygon->simplifiedRing(0.01);

        $this->assertLessThan($polygon->vertexCount(), count($simplified));
        $this->assertGreaterThanOrEqual(3, count($simplified), 'must remain a closable ring');
    }

    public function test_a_tiny_polygon_survives_simplification_unchanged(): void
    {
        $polygon = $this->square();
        $this->assertCount(5, $polygon->simplifiedRing(10.0));
    }
}
