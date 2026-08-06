<?php

namespace LibreNMS\Plugins\WeatherRiskMap\Tests\Unit;

use Carbon\CarbonImmutable;
use LibreNMS\Plugins\WeatherRiskMap\DTO\Coordinate;
use LibreNMS\Plugins\WeatherRiskMap\DTO\Forecast;
use LibreNMS\Plugins\WeatherRiskMap\DTO\ForecastPoint;
use LibreNMS\Plugins\WeatherRiskMap\DTO\WeatherWarning;
use LibreNMS\Plugins\WeatherRiskMap\Enums\HazardType;
use LibreNMS\Plugins\WeatherRiskMap\Enums\RiskLevel;
use LibreNMS\Plugins\WeatherRiskMap\Services\SensitivityProfileService;
use LibreNMS\Plugins\WeatherRiskMap\Services\WeatherRiskService;
use PHPUnit\Framework\TestCase;

/**
 * The risk algorithm, including the max(calculated, official) rule that the
 * specification calls out explicitly.
 */
final class WeatherRiskServiceTest extends TestCase
{
    private CarbonImmutable $now;

    protected function setUp(): void
    {
        $this->now = CarbonImmutable::parse('2026-08-06T12:00:00Z');
    }

    /** @param array<string, mixed> $overrides */
    private function service(array $overrides = [], ?SensitivityProfileService $sensitivity = null): WeatherRiskService
    {
        $thresholds = array_replace([
            'wind' => ['moderate' => 12.0, 'high' => 18.0, 'severe' => 25.0],
            'wind_gust' => ['moderate' => 17.0, 'high' => 24.0, 'severe' => 32.0],
            'rain_hourly' => ['moderate' => 4.0, 'high' => 8.0, 'severe' => 15.0],
            'rain_accumulated' => ['moderate' => 20.0, 'high' => 40.0, 'severe' => 70.0],
            'thunder_probability' => ['moderate' => 15.0, 'high' => 40.0, 'severe' => 70.0],
            'thunder_symbol_level' => 3,
            'snow_accumulated' => ['moderate' => 5.0, 'high' => 12.0, 'severe' => 25.0],
            'icing_temperature_band' => ['min' => -6.0, 'max' => 1.0],
            'icing_min_precipitation' => 0.1,
            'heat' => ['moderate' => 30.0, 'high' => 35.0, 'severe' => 40.0],
            'cold' => ['moderate' => -10.0, 'high' => -20.0, 'severe' => -30.0],
        ], $overrides);

        return new WeatherRiskService($thresholds, $sensitivity ?? new SensitivityProfileService([], false));
    }

    /** @param list<ForecastPoint> $points */
    private function forecast(array $points): Forecast
    {
        return new Forecast(
            coordinate: Coordinate::fromRaw(59.4138, 5.2680),
            points: $points,
            fetchedAt: $this->now,
            provider: 'test',
        );
    }

    private function point(array $attributes = [], int $hourOffset = 1): ForecastPoint
    {
        return new ForecastPoint(
            time: $this->now->addHours($hourOffset),
            temperature: $attributes['temperature'] ?? 10.0,
            windSpeed: $attributes['wind'] ?? 3.0,
            windGust: $attributes['gust'] ?? null,
            precipitation: $attributes['precipitation'] ?? 0.0,
            humidity: null,
            symbolCode: $attributes['symbol'] ?? 'cloudy',
            probabilityOfThunder: $attributes['thunder'] ?? null,
        );
    }

    private function allHazards(): array
    {
        return array_merge(HazardType::toggleable(), [HazardType::Other]);
    }

    // ------------------------------------------------------------------ wind

    public function test_calm_wind_produces_no_hazard(): void
    {
        $result = $this->service()->assess(
            $this->forecast([$this->point(['wind' => 5.0])]),
            [], $this->now, 24, $this->allHazards()
        );

        $this->assertArrayNotHasKey('wind', $result);
    }

    public function test_wind_thresholds_map_to_the_expected_levels(): void
    {
        $cases = [
            [13.0, RiskLevel::Moderate],
            [19.0, RiskLevel::High],
            [26.0, RiskLevel::Severe],
        ];

        foreach ($cases as [$speed, $expected]) {
            $result = $this->service()->assess(
                $this->forecast([$this->point(['wind' => $speed])]),
                [], $this->now, 24, $this->allHazards()
            );

            $this->assertSame($expected, $result['wind']->risk, "wind $speed m/s");
        }
    }

    public function test_gusts_are_scored_on_their_own_scale(): void
    {
        // 10 m/s sustained is calm, but a 33 m/s gust is severe on its own.
        $result = $this->service()->assess(
            $this->forecast([$this->point(['wind' => 10.0, 'gust' => 33.0])]),
            [], $this->now, 24, $this->allHazards()
        );

        $this->assertSame(RiskLevel::Severe, $result['wind']->risk);
        $this->assertStringContainsString('Gusts', $result['wind']->detail);
        $this->assertSame(33.0, $result['wind']->peakValue);
    }

    public function test_it_reports_the_worst_hour_and_its_time(): void
    {
        $result = $this->service()->assess(
            $this->forecast([
                $this->point(['wind' => 13.0], 1),
                $this->point(['wind' => 27.0], 4),   // the peak
                $this->point(['wind' => 14.0], 6),
            ]),
            [], $this->now, 24, $this->allHazards()
        );

        $this->assertSame(RiskLevel::Severe, $result['wind']->risk);
        $this->assertSame(27.0, $result['wind']->peakValue);
        $this->assertSame($this->now->addHours(4)->format('H:i'), $result['wind']->peakTime->format('H:i'));
    }

    // -------------------------------------------------------------- lightning

    public function test_lightning_from_a_symbol_is_flagged_as_inferred(): void
    {
        $result = $this->service()->assess(
            $this->forecast([$this->point(['symbol' => 'heavyrainandthunder'])]),
            [], $this->now, 24, $this->allHazards()
        );

        $this->assertArrayHasKey('lightning', $result);
        $this->assertTrue($result['lightning']->inferred, 'must not be presented as detected strikes');
        $this->assertStringContainsString('not detected strikes', $result['lightning']->detail);
    }

    public function test_lightning_uses_probability_when_available(): void
    {
        $result = $this->service()->assess(
            $this->forecast([$this->point(['thunder' => 80.0])]),
            [], $this->now, 24, $this->allHazards()
        );

        $this->assertSame(RiskLevel::Severe, $result['lightning']->risk);
    }

    // ---------------------------------------------------------- temperature

    public function test_heat_and_cold_use_opposite_directions(): void
    {
        $heat = $this->service()->assess(
            $this->forecast([$this->point(['temperature' => 36.0])]),
            [], $this->now, 24, $this->allHazards()
        );
        $this->assertSame(RiskLevel::High, $heat['heat']->risk);

        $cold = $this->service()->assess(
            $this->forecast([$this->point(['temperature' => -21.0])]),
            [], $this->now, 24, $this->allHazards()
        );
        $this->assertSame(RiskLevel::High, $cold['cold']->risk);
    }

    public function test_missing_temperature_is_treated_as_unknown_not_zero(): void
    {
        $point = new ForecastPoint(time: $this->now->addHour(), temperature: null, windSpeed: 3.0);

        $result = $this->service()->assess(
            $this->forecast([$point]), [], $this->now, 24, $this->allHazards()
        );

        // A null temperature must not be read as 0 C and trigger a cold hazard.
        $this->assertArrayNotHasKey('cold', $result);
        $this->assertArrayNotHasKey('heat', $result);
    }

    // ---------------------------------------------------------------- rain

    public function test_accumulated_rain_triggers_even_without_an_intense_hour(): void
    {
        // 8 h x 3.5 mm = 28 mm. Every hour is under the 4.0 mm/h moderate
        // threshold, so only the accumulated total (moderate 20, high 40) can
        // raise this - which is the point of scoring accumulation separately.
        $points = [];
        for ($i = 1; $i <= 8; $i++) {
            $points[] = $this->point(['precipitation' => 3.5], $i);
        }

        $result = $this->service()->assess(
            $this->forecast($points), [], $this->now, 24, $this->allHazards()
        );

        $this->assertArrayHasKey('rain', $result);
        $this->assertSame(RiskLevel::Moderate, $result['rain']->risk);
        $this->assertStringContainsString('total', $result['rain']->detail);
    }

    public function test_accumulated_rain_escalates_with_the_total(): void
    {
        // 16 h x 3.5 mm = 56 mm, still with no intense hour, crosses into High.
        $points = [];
        for ($i = 1; $i <= 16; $i++) {
            $points[] = $this->point(['precipitation' => 3.5], $i);
        }

        $result = $this->service()->assess(
            $this->forecast($points), [], $this->now, 24, $this->allHazards()
        );

        $this->assertSame(RiskLevel::High, $result['rain']->risk);
    }

    // ------------------------------------------------------ official warnings

    private function warning(RiskLevel $risk, HazardType $hazard = HazardType::Wind): WeatherWarning
    {
        return new WeatherWarning(
            id: 'test-1',
            hazard: $hazard,
            risk: $risk,
            severityLabel: ucfirst($risk->color()),
            title: 'Strong wind',
            description: 'Test description',
            instruction: 'Secure loose objects',
            consequences: 'Possible power outages',
            area: 'Haugalandet',
            onset: $this->now->addHour(),
            expires: $this->now->addHours(8),
            polygons: [],
            source: 'MET Norway',
            sourceUrl: 'https://example.test',
            fetchedAt: $this->now,
            eventName: 'Strong wind',
        );
    }

    public function test_an_official_warning_raises_a_lower_calculated_risk(): void
    {
        // Calm wind locally, but MET has issued a red warning for the area.
        $result = $this->service()->assess(
            $this->forecast([$this->point(['wind' => 3.0])]),
            [$this->warning(RiskLevel::Severe)],
            $this->now, 24, $this->allHazards()
        );

        $this->assertSame(RiskLevel::Severe, $result['wind']->risk);
        $this->assertTrue($result['wind']->official);
    }

    public function test_a_higher_calculated_risk_is_not_lowered_by_a_milder_warning(): void
    {
        // 30 m/s locally (severe) with only a yellow warning issued.
        $result = $this->service()->assess(
            $this->forecast([$this->point(['wind' => 30.0])]),
            [$this->warning(RiskLevel::Moderate)],
            $this->now, 24, $this->allHazards()
        );

        $this->assertSame(RiskLevel::Severe, $result['wind']->risk, 'max() must keep the higher value');
        $this->assertTrue($result['wind']->official, 'the official warning is still surfaced');
    }

    public function test_a_warning_outside_the_window_is_ignored(): void
    {
        $future = new WeatherWarning(
            id: 'later', hazard: HazardType::Wind, risk: RiskLevel::Severe,
            severityLabel: 'Red', title: 'Later', description: null, instruction: null,
            consequences: null, area: null,
            onset: $this->now->addDays(5), expires: $this->now->addDays(6),
            polygons: [], source: 'MET Norway', sourceUrl: null, fetchedAt: $this->now,
        );

        $result = $this->service()->assess(
            $this->forecast([$this->point(['wind' => 3.0])]), [$future],
            $this->now, 24, $this->allHazards()
        );

        $this->assertArrayNotHasKey('wind', $result);
    }

    public function test_multiple_simultaneous_warnings_are_all_represented(): void
    {
        $result = $this->service()->assess(
            $this->forecast([$this->point()]),
            [
                $this->warning(RiskLevel::High, HazardType::Wind),
                $this->warning(RiskLevel::Moderate, HazardType::Rain),
                $this->warning(RiskLevel::Severe, HazardType::Flood),
            ],
            $this->now, 24, $this->allHazards()
        );

        $this->assertArrayHasKey('wind', $result);
        $this->assertArrayHasKey('rain', $result);
        $this->assertArrayHasKey('flood', $result);
    }

    // -------------------------------------------------------------- overall

    public function test_two_severe_hazards_escalate_to_extreme(): void
    {
        $service = $this->service();

        $assessments = $service->assess(
            $this->forecast([$this->point(['wind' => 30.0, 'temperature' => -32.0])]),
            [], $this->now, 24, $this->allHazards()
        );

        $this->assertSame(RiskLevel::Extreme, $service->overallRisk($assessments));
    }

    public function test_a_single_severe_hazard_stays_severe(): void
    {
        $service = $this->service();

        $assessments = $service->assess(
            $this->forecast([$this->point(['wind' => 30.0])]),
            [], $this->now, 24, $this->allHazards()
        );

        $this->assertSame(RiskLevel::Severe, $service->overallRisk($assessments));
    }

    public function test_no_hazards_means_normal(): void
    {
        $this->assertSame(RiskLevel::Normal, $this->service()->overallRisk([]));
    }

    // --------------------------------------------------------------- filters

    public function test_disabled_hazards_are_excluded(): void
    {
        $result = $this->service()->assess(
            $this->forecast([$this->point(['wind' => 30.0])]),
            [], $this->now, 24,
            [HazardType::Rain]   // wind switched off
        );

        $this->assertArrayNotHasKey('wind', $result);
    }

    public function test_a_missing_forecast_still_yields_official_warnings(): void
    {
        // Provider outage: no forecast, but cached warnings still apply.
        $result = $this->service()->assess(
            null, [$this->warning(RiskLevel::High)], $this->now, 24, $this->allHazards()
        );

        $this->assertArrayHasKey('wind', $result);
        $this->assertSame(RiskLevel::High, $result['wind']->risk);
    }

    // ----------------------------------------------------------- sensitivity

    public function test_a_sensitivity_profile_raises_the_matching_hazard(): void
    {
        $sensitivity = new SensitivityProfileService([
            'tower' => ['label' => 'Radio / tower sites', 'groups' => [7], 'hazards' => ['wind' => 1]],
        ]);

        $result = $this->service([], $sensitivity)->assess(
            $this->forecast([$this->point(['wind' => 13.0])]),   // normally Moderate
            [], $this->now, 24, $this->allHazards(),
            [7]
        );

        $this->assertSame(RiskLevel::High, $result['wind']->risk);
    }

    public function test_a_profile_does_not_affect_devices_in_other_groups(): void
    {
        $sensitivity = new SensitivityProfileService([
            'tower' => ['groups' => [7], 'hazards' => ['wind' => 1]],
        ]);

        $result = $this->service([], $sensitivity)->assess(
            $this->forecast([$this->point(['wind' => 13.0])]),
            [], $this->now, 24, $this->allHazards(),
            [99]
        );

        $this->assertSame(RiskLevel::Moderate, $result['wind']->risk);
    }

    public function test_a_negative_offset_lowers_risk_for_hardened_sites(): void
    {
        $sensitivity = new SensitivityProfileService([
            'dc' => ['groups' => [1], 'hazards' => ['wind' => -1]],
        ]);

        $result = $this->service([], $sensitivity)->assess(
            $this->forecast([$this->point(['wind' => 19.0])]),   // normally High
            [], $this->now, 24, $this->allHazards(),
            [1]
        );

        $this->assertSame(RiskLevel::Moderate, $result['wind']->risk);
    }
}
