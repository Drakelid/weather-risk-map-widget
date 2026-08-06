<?php

namespace LibreNMS\Plugins\WeatherRiskMap\Enums;

/**
 * Weather hazard classes relevant to ISP infrastructure.
 *
 * The string values are stable identifiers used in widget settings, cache
 * payloads and the front end. Renaming one silently disables that hazard's
 * saved toggle, so treat them as a public contract.
 */
enum HazardType: string
{
    case Wind = 'wind';
    case Storm = 'storm';
    case Lightning = 'lightning';
    case Rain = 'rain';
    case Flood = 'flood';
    case Snow = 'snow';
    case Icing = 'icing';
    case Heat = 'heat';
    case Cold = 'cold';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Wind => 'Wind',
            self::Storm => 'Storm',
            self::Lightning => 'Lightning',
            self::Rain => 'Heavy rain',
            self::Flood => 'Flood',
            self::Snow => 'Snow',
            self::Icing => 'Icing',
            self::Heat => 'Extreme heat',
            self::Cold => 'Extreme cold',
            self::Other => 'Other',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Wind => '💨',
            self::Storm => '🌀',
            self::Lightning => '⚡',
            self::Rain => '🌧',
            self::Flood => '🌊',
            self::Snow => '❄',
            self::Icing => '🧊',
            self::Heat => '🌡',
            self::Cold => '🥶',
            self::Other => '⚠',
        };
    }

    /**
     * Plain-language note on why this hazard matters to a network operator.
     * Shown in the device popup as "Potential ISP impact".
     *
     * These are our own operational notes, never presented as if they came
     * from the weather provider.
     */
    public function ispImpact(): string
    {
        return match ($this) {
            self::Wind => 'High wind exposure - antenna misalignment, mast/tower stress, falling-tree fibre cuts',
            self::Storm => 'Storm exposure - power loss and physical damage risk',
            self::Lightning => 'Surge/strike risk to radios, PoE and unprotected copper',
            self::Rain => 'Water ingress in cabinets, joints and ducts',
            self::Flood => 'Flooding risk to ground-level cabinets, ducts and basement rooms',
            self::Snow => 'Snow loading and site access difficulty for field engineers',
            self::Icing => 'Ice loading on masts and dishes, radio-path degradation',
            self::Heat => 'Cooling capacity strain in cabinets and equipment rooms',
            self::Cold => 'Battery capacity loss and cold-start problems',
            self::Other => 'Severe weather in the area',
        };
    }

    /** @return list<self> Hazards exposed as user-facing toggles. */
    public static function toggleable(): array
    {
        return [
            self::Wind,
            self::Storm,
            self::Lightning,
            self::Rain,
            self::Flood,
            self::Snow,
            self::Icing,
            self::Heat,
            self::Cold,
        ];
    }
}
