<?php

namespace LibreNMS\Plugins\WeatherRiskMap\Tests\Unit;

use LibreNMS\Plugins\WeatherRiskMap\DTO\Coordinate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers the specification's "Coordinates" test matrix: valid, missing,
 * invalid and duplicate coordinates.
 */
final class CoordinateTest extends TestCase
{
    public function test_it_accepts_a_valid_coordinate(): void
    {
        $coordinate = Coordinate::fromRaw(59.4138, 5.2680);

        $this->assertNotNull($coordinate);
        $this->assertSame(59.4138, $coordinate->lat);
        $this->assertSame(5.2680, $coordinate->lng);
    }

    public function test_it_accepts_numeric_strings_from_the_database(): void
    {
        // MySQL decimal columns arrive as strings through some drivers.
        $coordinate = Coordinate::fromRaw('59.4138', '5.2680');

        $this->assertNotNull($coordinate);
        $this->assertSame(59.4138, $coordinate->lat);
    }

    /** @return list<array{mixed, mixed, string}> */
    public static function invalidCoordinates(): array
    {
        return [
            [null, null, 'both null'],
            [59.41, null, 'null longitude'],
            [null, 5.26, 'null latitude'],
            ['', '', 'empty strings'],
            ['abc', 'def', 'non-numeric'],
            [0, 0, 'null island placeholder'],
            [0.0, 0.0, 'null island as floats'],
            [91.0, 5.0, 'latitude above 90'],
            [-91.0, 5.0, 'latitude below -90'],
            [59.0, 181.0, 'longitude above 180'],
            [59.0, -181.0, 'longitude below -180'],
            [INF, 5.0, 'infinite latitude'],
            [NAN, 5.0, 'NaN latitude'],
        ];
    }

    #[DataProvider('invalidCoordinates')]
    public function test_it_rejects_unusable_coordinates(mixed $lat, mixed $lng, string $case): void
    {
        $this->assertNull(Coordinate::fromRaw($lat, $lng), "should reject: $case");
    }

    public function test_boundary_values_are_accepted(): void
    {
        $this->assertNotNull(Coordinate::fromRaw(90.0, 180.0));
        $this->assertNotNull(Coordinate::fromRaw(-90.0, -180.0));
    }

    public function test_it_truncates_to_four_decimals_for_the_api(): void
    {
        // MET Norway answers 403 for coordinates with more than 4 decimals.
        $coordinate = Coordinate::fromRaw(59.41381234, 5.26801234);

        $this->assertNotNull($coordinate);
        $api = $coordinate->forApi();

        $this->assertSame(59.4138, $api->lat);
        $this->assertSame(5.268, $api->lng);
    }

    public function test_identical_coordinates_produce_identical_keys(): void
    {
        // Two devices in the same POP must collapse to one weather lookup.
        $a = Coordinate::fromRaw(59.4138, 5.2680);
        $b = Coordinate::fromRaw(59.4138, 5.2680);

        $this->assertSame($a->key(), $b->key());
    }

    public function test_nearly_identical_coordinates_share_an_api_key(): void
    {
        $a = Coordinate::fromRaw(59.41381, 5.26801)->forApi();
        $b = Coordinate::fromRaw(59.41382, 5.26802)->forApi();

        $this->assertSame($a->key(), $b->key());
    }
}
