<?php

namespace LibreNMS\Plugins\WeatherRiskMap\Services;

use Carbon\CarbonImmutable;
use LibreNMS\Plugins\WeatherRiskMap\DTO\Forecast;
use LibreNMS\Plugins\WeatherRiskMap\DTO\ForecastPoint;
use LibreNMS\Plugins\WeatherRiskMap\DTO\HazardAssessment;
use LibreNMS\Plugins\WeatherRiskMap\DTO\WeatherWarning;
use LibreNMS\Plugins\WeatherRiskMap\Enums\HazardType;
use LibreNMS\Plugins\WeatherRiskMap\Enums\RiskLevel;

/**
 * The weather risk algorithm.
 *
 * =====================================================================
 * ALGORITHM
 * =====================================================================
 *
 * For each device, over the selected time window:
 *
 * 1. CALCULATED RISK
 *    Every forecast hour in the window is scored against configurable
 *    thresholds, per hazard. The hazard's result is the WORST hour found,
 *    together with the time it occurs - the NOC needs the peak and when to
 *    expect it, not an average.
 *
 *    Wind uses the greater of sustained speed and gust, but gusts are also
 *    scored against their own (higher) thresholds, because a 25 m/s gust in
 *    a 12 m/s wind is a different engineering problem from a steady 25 m/s.
 *
 * 2. OFFICIAL WARNING RISK
 *    Warnings whose polygon contains the device and whose validity overlaps
 *    the window contribute their published severity
 *    (yellow -> Moderate, orange -> High, red -> Severe).
 *
 * 3. COMBINATION
 *    effectiveRisk = max(calculatedRisk, officialWarningRisk)
 *
 *    Official warnings therefore raise but never lower a locally calculated
 *    risk. A meteorologist's judgement about an area outranks our threshold
 *    table; equally, a genuinely dangerous local value is not suppressed
 *    just because no warning was issued.
 *
 * 4. INFRASTRUCTURE SENSITIVITY
 *    Device-group sensitivity profiles shift specific hazards up or down
 *    (a tower site is wind- and lightning-sensitive; a core datacentre is
 *    not). See SensitivityProfileService.
 *
 * 5. ESCALATION TO EXTREME
 *    A device carrying two or more hazards at Severe is promoted to Extreme.
 *    Compound events are what actually take sites off the air, and this makes
 *    them visually distinct (purple) on the map.
 *
 * LIGHTNING: no free, reliable public API provides real-time strike data, so
 * this is modelled as RISK from thunder indications in the forecast, never as
 * detected strikes, and is labelled as such throughout the UI. The
 * HazardAssessment::$inferred flag carries that distinction to the front end.
 * =====================================================================
 */
class WeatherRiskService
{
    /**
     * @param  array<string, mixed>  $thresholds  see config/weather-risk-map.php
     */
    public function __construct(
        private readonly array $thresholds,
        private readonly SensitivityProfileService $sensitivity,
    ) {}

    /**
     * Assess every hazard at one point.
     *
     * @param  list<WeatherWarning>  $warnings  already matched to this point
     * @param  list<HazardType>  $enabled  hazards the user has switched on
     * @param  list<int>  $deviceGroupIds  for sensitivity lookup
     * @return array<string, HazardAssessment>  keyed by hazard value
     */
    public function assess(
        ?Forecast $forecast,
        array $warnings,
        CarbonImmutable $from,
        int $windowHours,
        array $enabled,
        array $deviceGroupIds = [],
    ): array {
        $enabledValues = array_map(fn (HazardType $h) => $h->value, $enabled);

        $calculated = $forecast === null
            ? []
            : $this->assessForecast($forecast, $from, $windowHours);

        $official = $this->assessWarnings($warnings, $from, $windowHours);

        // Step 3: max() per hazard.
        $combined = $calculated;
        foreach ($official as $hazardValue => $assessment) {
            $existing = $combined[$hazardValue] ?? null;

            if ($existing === null || $assessment->risk->atLeast($existing->risk)) {
                $combined[$hazardValue] = $assessment;
                continue;
            }

            // Calculated risk is higher, but the fact that an official warning
            // exists is still operationally important - keep the higher level
            // while marking it as officially warned.
            $combined[$hazardValue] = new HazardAssessment(
                hazard: $existing->hazard,
                risk: $existing->risk,
                detail: $existing->detail,
                peakTime: $existing->peakTime,
                official: true,
                warningId: $assessment->warningId,
                peakValue: $existing->peakValue,
                unit: $existing->unit,
                inferred: $existing->inferred,
            );
        }

        // Step 4: sensitivity, applied only to hazards the user is watching.
        $result = [];
        foreach ($combined as $hazardValue => $assessment) {
            if (! in_array($hazardValue, $enabledValues, true)) {
                continue;
            }

            $adjusted = $this->sensitivity->adjust($assessment, $deviceGroupIds);

            // Normal-level hazards are noise in the popup and the table.
            if ($adjusted->risk === RiskLevel::Normal) {
                continue;
            }

            $result[$hazardValue] = $adjusted;
        }

        uasort($result, fn (HazardAssessment $a, HazardAssessment $b) => $b->risk->value <=> $a->risk->value);

        return $result;
    }

    /**
     * Overall device risk, including step 5 escalation.
     *
     * @param  array<string, HazardAssessment>  $assessments
     */
    public function overallRisk(array $assessments): RiskLevel
    {
        $risk = RiskLevel::Normal;
        $severeCount = 0;

        foreach ($assessments as $assessment) {
            $risk = $risk->max($assessment->risk);

            if ($assessment->risk->atLeast(RiskLevel::Severe)) {
                $severeCount++;
            }
        }

        // Step 5: compound severe weather is qualitatively worse.
        if ($severeCount >= 2) {
            return RiskLevel::Extreme;
        }

        return $risk;
    }

    // ------------------------------------------------------- calculated risk

    /**
     * @return array<string, HazardAssessment>
     */
    private function assessForecast(Forecast $forecast, CarbonImmutable $from, int $windowHours): array
    {
        $points = $forecast->window($from, $windowHours);
        if ($points === []) {
            return [];
        }

        $out = [];

        foreach ([
            HazardType::Wind->value => fn () => $this->assessWind($points),
            HazardType::Rain->value => fn () => $this->assessRain($points),
            HazardType::Lightning->value => fn () => $this->assessLightning($points),
            HazardType::Snow->value => fn () => $this->assessSnow($points),
            HazardType::Icing->value => fn () => $this->assessIcing($points),
            HazardType::Heat->value => fn () => $this->assessHeat($points),
            HazardType::Cold->value => fn () => $this->assessCold($points),
        ] as $key => $assessor) {
            $assessment = $assessor();
            if ($assessment !== null && $assessment->risk !== RiskLevel::Normal) {
                $out[$key] = $assessment;
            }
        }

        return $out;
    }

    /** @param list<ForecastPoint> $points */
    private function assessWind(array $points): ?HazardAssessment
    {
        $sustained = $this->thresholds['wind'] ?? [];
        $gustThresholds = $this->thresholds['wind_gust'] ?? [];

        $best = null;

        foreach ($points as $point) {
            // Sustained and gust are scored separately, then the worse wins.
            $level = RiskLevel::Normal;
            $value = null;
            $unit = 'm/s';
            $isGust = false;

            if ($point->windSpeed !== null) {
                $level = $this->levelFor($point->windSpeed, $sustained);
                $value = $point->windSpeed;
            }

            if ($point->windGust !== null) {
                $gustLevel = $this->levelFor($point->windGust, $gustThresholds);
                if ($gustLevel->atLeast($level) && $gustLevel !== RiskLevel::Normal) {
                    $level = $gustLevel;
                    $value = $point->windGust;
                    $isGust = true;
                }
            }

            if ($level === RiskLevel::Normal) {
                continue;
            }

            // Keep the worst hour; break ties on the higher wind figure so the
            // reported peak matches the time it actually occurs.
            $isWorse = $best === null
                || $level->value > $best['level']->value
                || ($level === $best['level'] && ($value ?? 0.0) > ($best['value'] ?? 0.0));

            if ($isWorse) {
                $best = ['level' => $level, 'value' => $value, 'time' => $point->time, 'gust' => $isGust, 'unit' => $unit];
            }
        }

        if ($best === null) {
            return null;
        }

        $detail = $best['gust']
            ? sprintf('Gusts %.0f m/s', $best['value'])
            : sprintf('Wind %.0f m/s', $best['value']);

        return new HazardAssessment(
            hazard: HazardType::Wind,
            risk: $best['level'],
            detail: $detail,
            peakTime: $best['time'],
            peakValue: $best['value'],
            unit: $best['unit'],
        );
    }

    /** @param list<ForecastPoint> $points */
    private function assessRain(array $points): ?HazardAssessment
    {
        $hourly = $this->thresholds['rain_hourly'] ?? [];
        $accumulated = $this->thresholds['rain_accumulated'] ?? [];

        $peak = null;
        $total = 0.0;

        foreach ($points as $point) {
            if ($point->precipitation === null) {
                continue;
            }

            $total += $point->precipitation;

            $level = $this->levelFor($point->precipitation, $hourly);
            if ($level !== RiskLevel::Normal && ($peak === null || $level->atLeast($peak['level']))) {
                $peak = ['level' => $level, 'value' => $point->precipitation, 'time' => $point->time];
            }
        }

        // Prolonged moderate rain saturates ground and floods ducts even when
        // no single hour is intense, so total accumulation is scored too.
        $accumulatedLevel = $this->levelFor($total, $accumulated);

        if ($peak === null && $accumulatedLevel === RiskLevel::Normal) {
            return null;
        }

        if ($peak === null || $accumulatedLevel->atLeast($peak['level'])) {
            if ($accumulatedLevel === RiskLevel::Normal) {
                return null;
            }

            return new HazardAssessment(
                hazard: HazardType::Rain,
                risk: $accumulatedLevel,
                detail: sprintf('%.0f mm total', $total),
                peakTime: $peak['time'] ?? null,
                peakValue: round($total, 1),
                unit: 'mm',
            );
        }

        return new HazardAssessment(
            hazard: HazardType::Rain,
            risk: $peak['level'],
            detail: sprintf('%.1f mm/h', $peak['value']),
            peakTime: $peak['time'],
            peakValue: $peak['value'],
            unit: 'mm/h',
        );
    }

    /**
     * Lightning RISK, not detected strikes.
     *
     * Two signals: an explicit probability_of_thunder when the provider
     * supplies one, otherwise the "...andthunder" symbol suffix. Both are
     * forecasts, so the assessment is always flagged inferred.
     *
     * @param  list<ForecastPoint>  $points
     */
    private function assessLightning(array $points): ?HazardAssessment
    {
        $probability = $this->thresholds['thunder_probability'] ?? [];
        $symbolLevel = RiskLevel::fromValue((int) ($this->thresholds['thunder_symbol_level'] ?? 3));

        $best = null;

        foreach ($points as $point) {
            $level = RiskLevel::Normal;
            $detail = null;
            $value = null;

            if ($point->probabilityOfThunder !== null) {
                $level = $this->levelFor($point->probabilityOfThunder, $probability);
                $detail = sprintf('%.0f%% thunder probability', $point->probabilityOfThunder);
                $value = $point->probabilityOfThunder;
            } elseif ($point->symbolIndicatesThunder()) {
                $level = $symbolLevel;
                $detail = 'Thunderstorm in forecast';
            }

            if ($level === RiskLevel::Normal || $detail === null) {
                continue;
            }

            if ($best === null || $level->atLeast($best['level'])) {
                $best = ['level' => $level, 'detail' => $detail, 'time' => $point->time, 'value' => $value];
            }
        }

        if ($best === null) {
            return null;
        }

        return new HazardAssessment(
            hazard: HazardType::Lightning,
            risk: $best['level'],
            detail: $best['detail'] . ' (risk, not detected strikes)',
            peakTime: $best['time'],
            peakValue: $best['value'],
            unit: $best['value'] === null ? null : '%',
            inferred: true,
        );
    }

    /** @param list<ForecastPoint> $points */
    private function assessSnow(array $points): ?HazardAssessment
    {
        $snowThresholds = $this->thresholds['snow_accumulated'] ?? [];
        $maxTemp = (float) ($this->thresholds['snow_max_temperature'] ?? 2.0);

        $total = 0.0;
        $peakTime = null;
        $peakAmount = 0.0;

        foreach ($points as $point) {
            if (! $point->symbolIndicatesSnow()) {
                continue;
            }

            // Precipitation is reported as liquid-water equivalent; only count
            // it as snow when it is actually cold enough to fall as snow.
            if ($point->temperature !== null && $point->temperature > $maxTemp) {
                continue;
            }

            $amount = $point->precipitation ?? 0.0;
            $total += $amount;

            if ($amount >= $peakAmount) {
                $peakAmount = $amount;
                $peakTime = $point->time;
            }
        }

        if ($total <= 0.0) {
            return null;
        }

        $level = $this->levelFor($total, $snowThresholds);
        if ($level === RiskLevel::Normal) {
            return null;
        }

        return new HazardAssessment(
            hazard: HazardType::Snow,
            risk: $level,
            detail: sprintf('%.1f mm snow (water equivalent)', $total),
            peakTime: $peakTime,
            peakValue: round($total, 1),
            unit: 'mm',
        );
    }

    /**
     * Freezing rain / icing.
     *
     * Modelled from the physical precondition: precipitation while the air
     * temperature sits in the icing band just below freezing. Sleet symbols
     * near 0 C are the strongest available indicator in both providers.
     *
     * @param  list<ForecastPoint>  $points
     */
    private function assessIcing(array $points): ?HazardAssessment
    {
        $band = $this->thresholds['icing_temperature_band'] ?? ['min' => -6.0, 'max' => 1.0];
        $minPrecip = (float) ($this->thresholds['icing_min_precipitation'] ?? 0.1);

        $best = null;

        foreach ($points as $point) {
            if ($point->temperature === null || $point->precipitation === null) {
                continue;
            }

            if ($point->precipitation < $minPrecip) {
                continue;
            }

            if ($point->temperature < (float) $band['min'] || $point->temperature > (float) $band['max']) {
                continue;
            }

            // Freezing rain (sleet at/below zero) is markedly worse than wet
            // snow: it accretes as clear ice on masts, dishes and guy wires.
            $level = $point->symbolIndicatesSleet() ? RiskLevel::High : RiskLevel::Moderate;

            if ($point->precipitation >= ($minPrecip * 10)) {
                $level = $level->max(RiskLevel::High);
            }

            if ($best === null || $level->atLeast($best['level'])) {
                $best = ['level' => $level, 'time' => $point->time, 'temp' => $point->temperature];
            }
        }

        if ($best === null) {
            return null;
        }

        return new HazardAssessment(
            hazard: HazardType::Icing,
            risk: $best['level'],
            detail: sprintf('Icing conditions at %.1f C', $best['temp']),
            peakTime: $best['time'],
            peakValue: $best['temp'],
            unit: 'C',
            inferred: true,
        );
    }

    /** @param list<ForecastPoint> $points */
    private function assessHeat(array $points): ?HazardAssessment
    {
        return $this->assessTemperature($points, HazardType::Heat, $this->thresholds['heat'] ?? [], ascending: true);
    }

    /** @param list<ForecastPoint> $points */
    private function assessCold(array $points): ?HazardAssessment
    {
        return $this->assessTemperature($points, HazardType::Cold, $this->thresholds['cold'] ?? [], ascending: false);
    }

    /**
     * @param  list<ForecastPoint>  $points
     * @param  array<string, mixed>  $thresholds
     */
    private function assessTemperature(array $points, HazardType $hazard, array $thresholds, bool $ascending): ?HazardAssessment
    {
        $best = null;

        foreach ($points as $point) {
            if ($point->temperature === null) {
                continue;
            }

            $level = $ascending
                ? $this->levelFor($point->temperature, $thresholds)
                : $this->levelForDescending($point->temperature, $thresholds);

            if ($level === RiskLevel::Normal) {
                continue;
            }

            $isWorse = $best === null
                || $level->value > $best['level']->value
                || ($level === $best['level'] && ($ascending
                    ? $point->temperature > $best['temp']
                    : $point->temperature < $best['temp']));

            if ($isWorse) {
                $best = ['level' => $level, 'temp' => $point->temperature, 'time' => $point->time];
            }
        }

        if ($best === null) {
            return null;
        }

        return new HazardAssessment(
            hazard: $hazard,
            risk: $best['level'],
            detail: sprintf('%.0f C', $best['temp']),
            peakTime: $best['time'],
            peakValue: $best['temp'],
            unit: 'C',
        );
    }

    // -------------------------------------------------------- official risk

    /**
     * @param  list<WeatherWarning>  $warnings
     * @return array<string, HazardAssessment>
     */
    private function assessWarnings(array $warnings, CarbonImmutable $from, int $windowHours): array
    {
        $until = $from->addHours(max(1, $windowHours));
        $out = [];

        foreach ($warnings as $warning) {
            if (! $warning->overlapsWindow($from, $until)) {
                continue;
            }

            $key = $warning->hazard->value;
            $existing = $out[$key] ?? null;

            if ($existing !== null && $existing->risk->atLeast($warning->risk)) {
                continue;
            }

            $detail = $warning->eventName ?? $warning->title;
            $period = $warning->formattedPeriod();
            if ($period !== null) {
                $detail .= " ($period)";
            }

            $out[$key] = new HazardAssessment(
                hazard: $warning->hazard,
                risk: $warning->risk,
                detail: $detail,
                peakTime: $warning->onset,
                official: true,
                warningId: $warning->id,
            );
        }

        return $out;
    }

    // ------------------------------------------------------------- helpers

    /**
     * Map a value onto a risk level using ascending thresholds.
     *
     * Config shape: ['moderate' => 12, 'high' => 18, 'severe' => 25]
     * A value >= severe is Severe, >= high is High, and so on.
     *
     * @param  array<string, mixed>  $thresholds
     */
    private function levelFor(float $value, array $thresholds): RiskLevel
    {
        foreach ([
            'extreme' => RiskLevel::Extreme,
            'severe' => RiskLevel::Severe,
            'high' => RiskLevel::High,
            'moderate' => RiskLevel::Moderate,
            'info' => RiskLevel::Info,
        ] as $name => $level) {
            if (! isset($thresholds[$name]) || ! is_numeric($thresholds[$name])) {
                continue;
            }

            if ($value >= (float) $thresholds[$name]) {
                return $level;
            }
        }

        return RiskLevel::Normal;
    }

    /**
     * As levelFor(), but for hazards that worsen as the value falls (cold).
     *
     * @param  array<string, mixed>  $thresholds
     */
    private function levelForDescending(float $value, array $thresholds): RiskLevel
    {
        foreach ([
            'extreme' => RiskLevel::Extreme,
            'severe' => RiskLevel::Severe,
            'high' => RiskLevel::High,
            'moderate' => RiskLevel::Moderate,
            'info' => RiskLevel::Info,
        ] as $name => $level) {
            if (! isset($thresholds[$name]) || ! is_numeric($thresholds[$name])) {
                continue;
            }

            if ($value <= (float) $thresholds[$name]) {
                return $level;
            }
        }

        return RiskLevel::Normal;
    }
}
