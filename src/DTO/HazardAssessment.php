<?php

namespace LibreNMS\Plugins\WeatherRiskMap\DTO;

use Carbon\CarbonImmutable;
use LibreNMS\Plugins\WeatherRiskMap\Enums\HazardType;
use LibreNMS\Plugins\WeatherRiskMap\Enums\RiskLevel;

/**
 * The peak of one hazard at one place over the selected time window.
 *
 * `official` records whether the level came from a government warning or from
 * our own threshold calculation. The UI must keep that distinction visible:
 * an operator acts differently on "MET issued an orange wind warning" than on
 * "our threshold says 19 m/s".
 */
final class HazardAssessment
{
    public function __construct(
        public readonly HazardType $hazard,
        public readonly RiskLevel $risk,
        public readonly string $detail,
        public readonly ?CarbonImmutable $peakTime = null,
        public readonly bool $official = false,
        public readonly ?string $warningId = null,
        public readonly ?float $peakValue = null,
        public readonly ?string $unit = null,
        public readonly bool $inferred = false,
    ) {}

    public function withRisk(RiskLevel $risk): self
    {
        return new self(
            $this->hazard,
            $risk,
            $this->detail,
            $this->peakTime,
            $this->official,
            $this->warningId,
            $this->peakValue,
            $this->unit,
            $this->inferred,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'hazard' => $this->hazard->value,
            'hazard_label' => $this->hazard->label(),
            'hazard_icon' => $this->hazard->icon(),
            'risk' => $this->risk->value,
            'risk_label' => $this->risk->label(),
            'risk_color' => $this->risk->hex(),
            'detail' => $this->detail,
            // ISO only. A server-formatted "H:i" would be rendered in the
            // application timezone while the browser formats warning times in
            // the viewer's, putting two different clocks in one table.
            'peak_time' => $this->peakTime?->toIso8601String(),
            'peak_value' => $this->peakValue,
            'unit' => $this->unit,
            'official' => $this->official,
            'inferred' => $this->inferred,
            'warning_id' => $this->warningId,
            'impact' => $this->hazard->ispImpact(),
        ];
    }
}
