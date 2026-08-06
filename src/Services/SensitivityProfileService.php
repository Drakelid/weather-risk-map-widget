<?php

namespace LibreNMS\Plugins\WeatherRiskMap\Services;

use LibreNMS\Plugins\WeatherRiskMap\DTO\HazardAssessment;
use LibreNMS\Plugins\WeatherRiskMap\Enums\RiskLevel;

/**
 * Per-device-group weather sensitivity.
 *
 * The same 22 m/s wind is a different problem for a guyed radio mast than for
 * a core datacentre, so a profile shifts individual hazards up or down for
 * devices in a given LibreNMS device group.
 *
 * Configuration (config/weather-risk-map.php, key `sensitivity`):
 *
 *     'profiles' => [
 *         'tower' => [
 *             'label'   => 'Radio / tower sites',
 *             'groups'  => [3, 7],        // LibreNMS device_group ids
 *             'hazards' => ['wind' => +1, 'lightning' => +1, 'icing' => +1],
 *         ],
 *     ],
 *
 * Groups are referenced by numeric id, matching LibreNMS's own relationships;
 * names are never string-matched. The offset is applied to the risk ordinal
 * and clamped to the 0-5 scale.
 *
 * Deliberately kept as a flat offset table rather than a rules engine: it
 * covers the stated cases, is trivial to reason about in a NOC, and the
 * adjust() seam is where a richer engine would later slot in without
 * touching callers.
 */
class SensitivityProfileService
{
    /**
     * @param  array<string, array{label?: string, groups?: list<int|string>, hazards?: array<string, int|float>}>  $profiles
     */
    public function __construct(
        private readonly array $profiles = [],
        private readonly bool $enabled = true,
    ) {}

    /**
     * Apply the strongest matching offset for this hazard.
     *
     * When a device belongs to several groups with conflicting profiles the
     * largest offset wins - erring toward showing more risk, never less.
     *
     * @param  list<int>  $deviceGroupIds
     */
    public function adjust(HazardAssessment $assessment, array $deviceGroupIds): HazardAssessment
    {
        if (! $this->enabled || $this->profiles === [] || $deviceGroupIds === []) {
            return $assessment;
        }

        $offset = $this->offsetFor($assessment->hazard->value, $deviceGroupIds);

        if ($offset === 0) {
            return $assessment;
        }

        return $assessment->withRisk(RiskLevel::fromValue($assessment->risk->value + $offset));
    }

    /**
     * @param  list<int>  $deviceGroupIds
     */
    public function offsetFor(string $hazard, array $deviceGroupIds): int
    {
        $offset = 0;
        $found = false;

        foreach ($this->profiles as $profile) {
            $groups = array_map('intval', (array) ($profile['groups'] ?? []));
            if ($groups === [] || array_intersect($groups, $deviceGroupIds) === []) {
                continue;
            }

            $hazards = (array) ($profile['hazards'] ?? []);
            if (! array_key_exists($hazard, $hazards) || ! is_numeric($hazards[$hazard])) {
                continue;
            }

            $candidate = (int) round((float) $hazards[$hazard]);

            $offset = $found ? max($offset, $candidate) : $candidate;
            $found = true;
        }

        return $offset;
    }

    /**
     * Profile names matching a device, for display in the popup so an
     * operator can see why a device scored differently from its neighbour.
     *
     * @param  list<int>  $deviceGroupIds
     * @return list<string>
     */
    public function labelsFor(array $deviceGroupIds): array
    {
        if (! $this->enabled || $deviceGroupIds === []) {
            return [];
        }

        $labels = [];

        foreach ($this->profiles as $name => $profile) {
            $groups = array_map('intval', (array) ($profile['groups'] ?? []));
            if ($groups !== [] && array_intersect($groups, $deviceGroupIds) !== []) {
                $labels[] = (string) ($profile['label'] ?? $name);
            }
        }

        return array_values(array_unique($labels));
    }
}
