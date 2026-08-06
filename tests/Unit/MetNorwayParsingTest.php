<?php

namespace LibreNMS\Plugins\WeatherRiskMap\Tests\Unit;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use LibreNMS\Plugins\WeatherRiskMap\DTO\Coordinate;
use LibreNMS\Plugins\WeatherRiskMap\Enums\HazardType;
use LibreNMS\Plugins\WeatherRiskMap\Enums\RiskLevel;
use LibreNMS\Plugins\WeatherRiskMap\Providers\MetNorwayWeatherProvider;
use LibreNMS\Plugins\WeatherRiskMap\Services\WeatherCacheService;
use LibreNMS\Plugins\WeatherRiskMap\Tests\Support\ArrayCacheStub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Response parsing against payload shapes matching the documented MET Norway
 * schemas, including the malformed-response cases the specification requires.
 */
final class MetNorwayParsingTest extends TestCase
{
    private function provider(): MetNorwayWeatherProvider
    {
        return new MetNorwayWeatherProvider(
            http: new HttpFactory(),
            cache: new WeatherCacheService(new ArrayCacheStub(), new NullLogger()),
            logger: new NullLogger(),
            userAgent: 'LibreNMS-WeatherRiskMap/test test@example.com',
        );
    }

    private function coordinate(): Coordinate
    {
        return Coordinate::fromRaw(59.4138, 5.2680);
    }

    // ------------------------------------------------------------- forecast

    public function test_it_parses_a_locationforecast_payload(): void
    {
        $json = [
            'properties' => [
                'timeseries' => [
                    [
                        'time' => '2026-08-06T12:00:00Z',
                        'data' => [
                            'instant' => ['details' => [
                                'air_temperature' => 7.2,
                                'wind_speed' => 18.4,
                                'wind_speed_of_gust' => 27.1,
                                'relative_humidity' => 88.0,
                            ]],
                            'next_1_hours' => [
                                'summary' => ['symbol_code' => 'heavyrainandthunder'],
                                'details' => ['precipitation_amount' => 6.4],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $forecast = $this->provider()->hydrateForecast($json, $this->coordinate());

        $this->assertNotNull($forecast);
        $this->assertCount(1, $forecast->points);

        $point = $forecast->points[0];
        $this->assertSame(7.2, $point->temperature);
        $this->assertSame(18.4, $point->windSpeed);
        $this->assertSame(27.1, $point->windGust);
        $this->assertSame(6.4, $point->precipitation);
        $this->assertTrue($point->symbolIndicatesThunder());
    }

    public function test_it_falls_back_to_next_6_hours_when_next_1_is_absent(): void
    {
        // MET drops next_1_hours beyond roughly two days out.
        $json = ['properties' => ['timeseries' => [[
            'time' => '2026-08-08T12:00:00Z',
            'data' => [
                'instant' => ['details' => ['air_temperature' => 3.0]],
                'next_6_hours' => [
                    'summary' => ['symbol_code' => 'snow'],
                    'details' => ['precipitation_amount' => 12.0],
                ],
            ],
        ]]]];

        $forecast = $this->provider()->hydrateForecast($json, $this->coordinate());

        $this->assertNotNull($forecast);
        $this->assertSame(12.0, $forecast->points[0]->precipitation);
        $this->assertTrue($forecast->points[0]->symbolIndicatesSnow());
    }

    public function test_missing_variables_become_null_not_zero(): void
    {
        $json = ['properties' => ['timeseries' => [[
            'time' => '2026-08-06T12:00:00Z',
            'data' => ['instant' => ['details' => ['air_temperature' => 5.0]]],
        ]]]];

        $forecast = $this->provider()->hydrateForecast($json, $this->coordinate());

        $this->assertNotNull($forecast);
        $this->assertNull($forecast->points[0]->windSpeed);
        $this->assertNull($forecast->points[0]->precipitation);
    }

    public function test_malformed_forecast_payloads_return_null(): void
    {
        $provider = $this->provider();

        $this->assertNull($provider->hydrateForecast([], $this->coordinate()));
        $this->assertNull($provider->hydrateForecast(['properties' => []], $this->coordinate()));
        $this->assertNull($provider->hydrateForecast(['properties' => ['timeseries' => 'nope']], $this->coordinate()));
        $this->assertNull($provider->hydrateForecast(['properties' => ['timeseries' => []]], $this->coordinate()));
    }

    public function test_entries_with_unparseable_times_are_skipped(): void
    {
        $json = ['properties' => ['timeseries' => [
            ['time' => 'not-a-date', 'data' => ['instant' => ['details' => ['air_temperature' => 1.0]]]],
            ['time' => '2026-08-06T12:00:00Z', 'data' => ['instant' => ['details' => ['air_temperature' => 2.0]]]],
        ]]];

        $forecast = $this->provider()->hydrateForecast($json, $this->coordinate());

        $this->assertNotNull($forecast);
        $this->assertCount(1, $forecast->points);
        $this->assertSame(2.0, $forecast->points[0]->temperature);
    }

    // ------------------------------------------------------------- warnings

    /** @param array<string, mixed> $properties */
    private function feature(array $properties, bool $withGeometry = true): array
    {
        return [
            'type' => 'Feature',
            'properties' => $properties,
            'geometry' => $withGeometry ? [
                'type' => 'Polygon',
                'coordinates' => [[[5.0, 59.0], [7.0, 59.0], [7.0, 61.0], [5.0, 61.0], [5.0, 59.0]]],
            ] : null,
        ];
    }

    public function test_it_parses_a_metalerts_feature_collection(): void
    {
        $json = ['type' => 'FeatureCollection', 'features' => [
            $this->feature([
                'id' => '2.49.0.1.578.0.test',
                'event' => 'wind',
                'eventAwarenessName' => 'Strong wind',
                'riskMatrixColor' => 'Orange',
                'awareness_level' => '3; orange; Severe',
                'severity' => 'Severe',
                'area' => 'Haugalandet',
                'description' => 'Sorlig liten storm.',
                'instruction' => 'Sikre lose gjenstander.',
                'consequences' => 'Fare for strombrudd.',
                'effective' => '2026-08-06T14:00:00+00:00',
                'expires' => '2026-08-06T21:00:00+00:00',
                'web' => 'https://www.yr.no/en/browse/warnings',
            ]),
        ]];

        $warnings = $this->provider()->hydrateWarnings($json, CarbonImmutable::now());

        $this->assertCount(1, $warnings);
        $warning = $warnings[0];

        $this->assertSame('2.49.0.1.578.0.test', $warning->id);
        $this->assertSame(HazardType::Wind, $warning->hazard);
        $this->assertSame(RiskLevel::High, $warning->risk, 'orange maps to High');
        $this->assertSame('Orange', $warning->severityLabel);
        $this->assertSame('Strong wind', $warning->eventName);
        $this->assertSame('Haugalandet', $warning->area);
        $this->assertSame('Sikre lose gjenstander.', $warning->instruction);
        $this->assertSame('Fare for strombrudd.', $warning->consequences);
        $this->assertCount(1, $warning->polygons);
        $this->assertSame('14:00-21:00', $warning->formattedPeriod());
    }

    public function test_warning_colours_map_to_the_specified_levels(): void
    {
        foreach ([
            'Yellow' => RiskLevel::Moderate,
            'Orange' => RiskLevel::High,
            'Red' => RiskLevel::Severe,
        ] as $colour => $expected) {
            $json = ['features' => [$this->feature([
                'event' => 'wind', 'riskMatrixColor' => $colour,
            ])]];

            $warnings = $this->provider()->hydrateWarnings($json, CarbonImmutable::now());
            $this->assertSame($expected, $warnings[0]->risk, "colour $colour");
        }
    }

    public function test_it_falls_back_to_awareness_level_when_colour_is_absent(): void
    {
        $json = ['features' => [$this->feature([
            'event' => 'rain', 'awareness_level' => '2; yellow; Moderate',
        ])]];

        $warnings = $this->provider()->hydrateWarnings($json, CarbonImmutable::now());

        $this->assertSame(RiskLevel::Moderate, $warnings[0]->risk);
        $this->assertSame('Yellow', $warnings[0]->severityLabel);
    }

    public function test_event_types_map_to_hazards(): void
    {
        foreach ([
            'wind' => HazardType::Wind,
            'gale' => HazardType::Storm,
            'polarLow' => HazardType::Storm,
            'lightning' => HazardType::Lightning,
            'rain' => HazardType::Rain,
            'rainFlood' => HazardType::Flood,
            'stormSurge' => HazardType::Flood,
            'snow' => HazardType::Snow,
            'blowingSnow' => HazardType::Snow,
            'ice' => HazardType::Icing,
            'icing' => HazardType::Icing,
            'forestFire' => HazardType::Other,
        ] as $event => $expected) {
            $json = ['features' => [$this->feature(['event' => $event, 'riskMatrixColor' => 'Yellow'])]];
            $warnings = $this->provider()->hydrateWarnings($json, CarbonImmutable::now());

            $this->assertSame($expected, $warnings[0]->hazard, "event $event");
        }
    }

    public function test_no_warnings_is_a_valid_empty_result(): void
    {
        $warnings = $this->provider()->hydrateWarnings(
            ['type' => 'FeatureCollection', 'features' => []],
            CarbonImmutable::now()
        );

        $this->assertSame([], $warnings);
    }

    public function test_multipolygon_geometry_is_supported(): void
    {
        $json = ['features' => [[
            'properties' => ['event' => 'wind', 'riskMatrixColor' => 'Red'],
            'geometry' => ['type' => 'MultiPolygon', 'coordinates' => [
                [[[5.0, 59.0], [6.0, 59.0], [6.0, 60.0], [5.0, 59.0]]],
                [[[10.0, 63.0], [11.0, 63.0], [11.0, 64.0], [10.0, 63.0]]],
            ]],
        ]]];

        $warnings = $this->provider()->hydrateWarnings($json, CarbonImmutable::now());

        $this->assertCount(2, $warnings[0]->polygons);
        $this->assertTrue($warnings[0]->containsPoint(Coordinate::fromRaw(59.3, 5.4)));
        $this->assertTrue($warnings[0]->containsPoint(Coordinate::fromRaw(63.3, 10.4)));
        $this->assertFalse($warnings[0]->containsPoint(Coordinate::fromRaw(70.0, 20.0)));
    }

    public function test_a_warning_without_geometry_still_parses(): void
    {
        $json = ['features' => [$this->feature(['event' => 'wind', 'riskMatrixColor' => 'Yellow'], withGeometry: false)]];

        $warnings = $this->provider()->hydrateWarnings($json, CarbonImmutable::now());

        $this->assertCount(1, $warnings);
        $this->assertSame([], $warnings[0]->polygons);
        // With no geometry it can match no device, but it is still listed.
        $this->assertFalse($warnings[0]->containsPoint(Coordinate::fromRaw(60.0, 6.0)));
    }

    public function test_malformed_features_are_skipped_without_throwing(): void
    {
        $json = ['features' => [
            'not-an-array',
            ['no-properties' => true],
            $this->feature(['event' => 'wind', 'riskMatrixColor' => 'Red']),
        ]];

        $warnings = $this->provider()->hydrateWarnings($json, CarbonImmutable::now());

        $this->assertCount(1, $warnings, 'the one good feature survives');
    }

    public function test_a_warning_without_an_id_gets_a_stable_derived_one(): void
    {
        $json = ['features' => [$this->feature([
            'event' => 'wind', 'area' => 'Test', 'effective' => '2026-08-06T14:00:00Z',
        ])]];

        $first = $this->provider()->hydrateWarnings($json, CarbonImmutable::now());
        $second = $this->provider()->hydrateWarnings($json, CarbonImmutable::now());

        $this->assertNotSame('', $first[0]->id);
        $this->assertSame($first[0]->id, $second[0]->id, 'must be stable across refreshes');
    }
}
