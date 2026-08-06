<?php

namespace LibreNMS\Plugins\WeatherRiskMap\Services;

use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Collection;
use LibreNMS\Plugins\WeatherRiskMap\DTO\Coordinate;
use Psr\Log\LoggerInterface;

/**
 * Retrieves the devices that belong on the map.
 *
 * Coordinates come exclusively from LibreNMS's own `locations` table via
 * `devices.location_id`; this package never stores device positions of its
 * own. Because many devices share one location row, a POP with 40 switches
 * yields a single coordinate before weather lookups even begin.
 *
 * All of this is one query with two eager-loaded relations - no N+1 - and the
 * group filter uses LibreNMS's own `inDeviceGroup` scope so membership is
 * resolved by group id, never by matching names.
 */
class DeviceLocationService
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param  list<int>  $deviceGroupIds  empty means all devices
     * @return Collection<int, array<string, mixed>>
     */
    public function devicesWithCoordinates(User $user, array $deviceGroupIds = []): Collection
    {
        $query = Device::query()
            ->select(['device_id', 'hostname', 'sysName', 'display', 'status', 'disabled', 'ignore', 'location_id', 'os', 'hardware'])
            // Devices with no location row can never be mapped; excluding them
            // in SQL keeps the payload small on large installs.
            ->whereNotNull('location_id')
            ->with([
                'location:id,location,lat,lng',
                'groups:device_groups.id,device_groups.name',
            ])
            ->hasAccess($user);

        if ($deviceGroupIds !== []) {
            $query->inDeviceGroup($deviceGroupIds);
        }

        $invalid = 0;

        $devices = $query->get()->map(function (Device $device) use (&$invalid): ?array {
            $location = $device->location;

            $coordinate = $location === null
                ? null
                : Coordinate::fromRaw($location->lat, $location->lng);

            if ($coordinate === null) {
                $invalid++;

                return null;
            }

            return [
                'device_id' => (int) $device->device_id,
                'hostname' => (string) $device->hostname,
                'display_name' => $device->displayName(),
                'sys_name' => (string) ($device->sysName ?? ''),
                'status' => (int) $device->status === 1,
                'disabled' => (bool) $device->disabled,
                'ignored' => (bool) $device->ignore,
                'location' => $location?->location ?? '',
                'location_id' => (int) $device->location_id,
                'coordinate' => $coordinate,
                'group_ids' => $device->groups->pluck('id')->map(fn ($id) => (int) $id)->all(),
                'group_names' => $device->groups->pluck('name')->all(),
                'os' => (string) ($device->os ?? ''),
                'hardware' => (string) ($device->hardware ?? ''),
            ];
        })->filter()->values();

        // One aggregate line per refresh, not one per bad device - the spec
        // explicitly warns against flooding the log during normal operation.
        if ($invalid > 0) {
            $this->logger->debug('weather-risk-map: devices skipped for unusable coordinates', [
                'skipped' => $invalid,
                'mapped' => $devices->count(),
            ]);
        }

        return $devices;
    }

    /**
     * Distinct coordinates keyed by location id.
     *
     * This is the first of the two grouping stages that keep external request
     * volume independent of device count.
     *
     * @param  Collection<int, array<string, mixed>>  $devices
     * @return array<int, Coordinate>
     */
    public function distinctLocations(Collection $devices): array
    {
        $locations = [];

        foreach ($devices as $device) {
            $locations[$device['location_id']] ??= $device['coordinate'];
        }

        return $locations;
    }
}
