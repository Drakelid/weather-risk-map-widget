<?php

namespace LibreNMS\Plugins\WeatherRiskMap\DTO;

use Carbon\CarbonImmutable;

/**
 * A forecast time series for one geographic cell.
 */
final class Forecast
{
    /**
     * @param  list<ForecastPoint>  $points  ordered ascending by time
     */
    public function __construct(
        public readonly Coordinate $coordinate,
        public readonly array $points,
        public readonly CarbonImmutable $fetchedAt,
        public readonly string $provider,
        public readonly ?CarbonImmutable $expiresAt = null,
        public readonly ?string $lastModified = null,
    ) {}

    /**
     * Points falling inside [now, now + $hours].
     *
     * "Current" (hours = 0) still returns the single nearest point rather than
     * an empty set, otherwise the widget would show nothing at all between
     * forecast steps.
     *
     * @return list<ForecastPoint>
     */
    public function window(CarbonImmutable $from, int $hours): array
    {
        if ($hours <= 0) {
            $nearest = $this->nearestTo($from);

            return $nearest ? [$nearest] : [];
        }

        $until = $from->addHours($hours);

        $points = array_values(array_filter(
            $this->points,
            fn (ForecastPoint $p) => $p->time->greaterThanOrEqualTo($from->subHour())
                && $p->time->lessThanOrEqualTo($until)
        ));

        // A series that starts after the requested window (stale cache, short
        // provider series) should still yield something usable.
        if ($points === []) {
            $nearest = $this->nearestTo($from);

            return $nearest ? [$nearest] : [];
        }

        return $points;
    }

    public function nearestTo(CarbonImmutable $time): ?ForecastPoint
    {
        $best = null;
        $bestDelta = PHP_INT_MAX;

        foreach ($this->points as $point) {
            $delta = abs($point->time->getTimestamp() - $time->getTimestamp());
            if ($delta < $bestDelta) {
                $bestDelta = $delta;
                $best = $point;
            }
        }

        return $best;
    }

    public function isEmpty(): bool
    {
        return $this->points === [];
    }

    public function ageInMinutes(?CarbonImmutable $now = null): int
    {
        return (int) $this->fetchedAt->diffInMinutes($now ?? CarbonImmutable::now(), true);
    }
}
