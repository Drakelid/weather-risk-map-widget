<?php

namespace LibreNMS\Plugins\WeatherRiskMap\DTO;

use Carbon\CarbonImmutable;
use LibreNMS\Plugins\WeatherRiskMap\Enums\HazardType;
use LibreNMS\Plugins\WeatherRiskMap\Enums\RiskLevel;
use LibreNMS\Plugins\WeatherRiskMap\Support\Polygon;

/**
 * An official severe-weather warning with its geographic extent.
 *
 * Text fields hold exactly what the issuing authority published. Nothing here
 * is generated or paraphrased by us - in particular `instruction` and
 * `consequences` are shown verbatim or not at all, since inventing safety
 * advice would be actively dangerous.
 */
final class WeatherWarning
{
    /**
     * @param  list<Polygon>  $polygons  affected area; may be empty if the
     *                                   source published a warning without geometry
     */
    public function __construct(
        public readonly string $id,
        public readonly HazardType $hazard,
        public readonly RiskLevel $risk,
        public readonly string $severityLabel,
        public readonly string $title,
        public readonly ?string $description,
        public readonly ?string $instruction,
        public readonly ?string $consequences,
        public readonly ?string $area,
        public readonly ?CarbonImmutable $onset,
        public readonly ?CarbonImmutable $expires,
        public readonly array $polygons,
        public readonly string $source,
        public readonly ?string $sourceUrl,
        public readonly CarbonImmutable $fetchedAt,
        public readonly ?string $eventName = null,
    ) {}

    public function containsPoint(Coordinate $point): bool
    {
        foreach ($this->polygons as $polygon) {
            if ($polygon->contains($point)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the warning is in force at any moment of the given window.
     *
     * A warning with no onset is treated as already active, and one with no
     * expiry as open-ended; both are common in CAP feeds and dropping them
     * would hide real warnings.
     */
    public function overlapsWindow(CarbonImmutable $from, CarbonImmutable $until): bool
    {
        if ($this->onset !== null && $this->onset->greaterThan($until)) {
            return false;
        }

        if ($this->expires !== null && $this->expires->lessThan($from)) {
            return false;
        }

        return true;
    }

    public function formattedPeriod(string $format = 'H:i'): ?string
    {
        if ($this->onset === null && $this->expires === null) {
            return null;
        }

        $start = $this->onset?->format($format) ?? '?';
        $end = $this->expires?->format($format) ?? '?';

        return "$start-$end";
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'hazard' => $this->hazard->value,
            'hazard_label' => $this->hazard->label(),
            'hazard_icon' => $this->hazard->icon(),
            'risk' => $this->risk->value,
            'risk_label' => $this->risk->label(),
            'risk_color' => $this->risk->hex(),
            'severity_label' => $this->severityLabel,
            'title' => $this->title,
            'description' => $this->description,
            'instruction' => $this->instruction,
            'consequences' => $this->consequences,
            'area' => $this->area,
            'event_name' => $this->eventName,
            'onset' => $this->onset?->toIso8601String(),
            'expires' => $this->expires?->toIso8601String(),
            'period' => $this->formattedPeriod(),
            'source' => $this->source,
            'source_url' => $this->sourceUrl,
            'fetched_at' => $this->fetchedAt->toIso8601String(),
        ];
    }
}
