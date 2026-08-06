<?php

namespace LibreNMS\Plugins\WeatherRiskMap\Tests\Unit;

use LibreNMS\Plugins\WeatherRiskMap\DTO\WidgetSettings;
use PHPUnit\Framework\TestCase;

/**
 * Widget settings are untrusted on every read, not just on save, because they
 * come from a JSON column a user controls.
 */
final class WidgetSettingsTest extends TestCase
{
    /** @return array<string, mixed> */
    private function config(): array
    {
        return [
            'defaults' => [
                'refresh' => 600, 'risk_period' => 24, 'min_severity' => 0,
                'weather_radius_km' => 10, 'clustering' => true,
                'show_warnings' => true, 'map_mode' => 'auto',
            ],
            'thresholds' => [
                'wind' => ['moderate' => 12.0, 'high' => 18.0, 'severe' => 25.0],
                'heat' => ['moderate' => 30.0, 'high' => 35.0, 'severe' => 40.0],
            ],
        ];
    }

    public function test_empty_settings_fall_back_to_defaults(): void
    {
        $settings = WidgetSettings::fromArray([], $this->config());

        $this->assertNull($settings->title);
        $this->assertSame([], $settings->deviceGroups);
        $this->assertSame(24, $settings->riskPeriod);
        $this->assertSame(10, $settings->weatherRadiusKm);
        $this->assertTrue($settings->clustering);
        $this->assertSame('auto', $settings->mapMode);
    }

    public function test_out_of_range_values_fall_back_rather_than_being_used(): void
    {
        $settings = WidgetSettings::fromArray([
            'risk_period' => 9999,
            'weather_radius_km' => 100000,
            'refresh' => 1,
            'map_mode' => 'evil',
            'min_severity' => 42,
        ], $this->config());

        $this->assertSame(24, $settings->riskPeriod);
        $this->assertSame(10, $settings->weatherRadiusKm);
        $this->assertSame(600, $settings->refresh);
        $this->assertSame('auto', $settings->mapMode);
        $this->assertSame(5, $settings->minSeverity, 'clamped, not rejected');
    }

    public function test_markup_is_stripped_from_the_title(): void
    {
        $settings = WidgetSettings::fromArray([
            'title' => '<script>alert(1)</script>Storm view',
        ], $this->config());

        $this->assertSame('alert(1)Storm view', $settings->title);
        $this->assertStringNotContainsString('<', (string) $settings->title);
    }

    public function test_device_groups_accept_array_csv_and_single_values(): void
    {
        $config = $this->config();

        $this->assertSame([1, 2, 3], WidgetSettings::fromArray(['device_groups' => [1, 2, 3]], $config)->deviceGroups);
        $this->assertSame([1, 2], WidgetSettings::fromArray(['device_groups' => '1,2'], $config)->deviceGroups);
        // A widget upgraded from a single-group setting.
        $this->assertSame([7], WidgetSettings::fromArray(['device_group' => 7], $config)->deviceGroups);
    }

    public function test_non_numeric_group_ids_are_discarded(): void
    {
        $settings = WidgetSettings::fromArray([
            'device_groups' => ['1', 'DROP TABLE devices', '', null, '3'],
        ], $this->config());

        $this->assertSame([1, 3], $settings->deviceGroups);
    }

    public function test_hazards_default_to_enabled_when_absent(): void
    {
        $settings = WidgetSettings::fromArray([], $this->config());

        foreach (['wind', 'rain', 'snow', 'lightning', 'flood', 'icing', 'heat', 'cold', 'storm'] as $hazard) {
            $this->assertTrue($settings->hazards[$hazard], "$hazard should default on");
        }
    }

    public function test_flat_hazard_keys_from_the_form_are_expanded(): void
    {
        // The settings form posts flat names because core's serializer does
        // not build nested arrays.
        $settings = WidgetSettings::fromArray([
            'hazard_wind' => '0',
            'hazard_rain' => '1',
        ], $this->config());

        $this->assertFalse($settings->hazards['wind']);
        $this->assertTrue($settings->hazards['rain']);
        $this->assertTrue($settings->hazards['snow'], 'untouched hazards stay on');
    }

    public function test_flat_threshold_keys_are_expanded_and_merged(): void
    {
        $settings = WidgetSettings::fromArray([
            'threshold_wind_high' => '20',
        ], $this->config());

        $this->assertSame(20.0, $settings->thresholds['wind']['high']);
        $this->assertSame(12.0, $settings->thresholds['wind']['moderate'], 'other levels keep the default');
        $this->assertSame(30.0, $settings->thresholds['heat']['moderate'], 'other hazards untouched');
    }

    public function test_a_threshold_group_containing_underscores_is_split_correctly(): void
    {
        $config = $this->config();
        $config['thresholds']['wind_gust'] = ['moderate' => 17.0, 'high' => 24.0, 'severe' => 32.0];

        $settings = WidgetSettings::fromArray(['threshold_wind_gust_severe' => '35'], $config);

        $this->assertSame(35.0, $settings->thresholds['wind_gust']['severe']);
        $this->assertSame(24.0, $settings->thresholds['wind_gust']['high']);
    }

    public function test_an_empty_threshold_field_keeps_the_default(): void
    {
        $settings = WidgetSettings::fromArray(['threshold_wind_high' => ''], $this->config());

        $this->assertSame(18.0, $settings->thresholds['wind']['high']);
    }

    public function test_unknown_threshold_keys_are_ignored(): void
    {
        $settings = WidgetSettings::fromArray([
            'threshold_madeup_high' => '5',
            'thresholds' => ['not_a_hazard' => ['high' => 1]],
        ], $this->config());

        $this->assertArrayNotHasKey('madeup', $settings->thresholds);
        $this->assertArrayNotHasKey('not_a_hazard', $settings->thresholds);
    }

    public function test_non_numeric_threshold_values_are_ignored(): void
    {
        $settings = WidgetSettings::fromArray([
            'thresholds' => ['wind' => ['high' => 'not-a-number']],
        ], $this->config());

        $this->assertSame(18.0, $settings->thresholds['wind']['high']);
    }

    public function test_checkbox_zero_values_disable_correctly(): void
    {
        $settings = WidgetSettings::fromArray([
            'clustering' => '0',
            'show_warnings' => '0',
        ], $this->config());

        $this->assertFalse($settings->clustering);
        $this->assertFalse($settings->showWarnings);
    }

    public function test_builder_settings_expose_only_what_the_builder_needs(): void
    {
        $settings = WidgetSettings::fromArray(['device_groups' => [4]], $this->config());
        $builder = $settings->toBuilderSettings();

        $this->assertSame([4], $builder['device_groups']);
        $this->assertSame(24, $builder['risk_period']);
        $this->assertArrayHasKey('hazards', $builder);
        $this->assertArrayNotHasKey('title', $builder);
    }

    public function test_current_conditions_period_is_allowed(): void
    {
        $settings = WidgetSettings::fromArray(['risk_period' => 0], $this->config());

        $this->assertSame(0, $settings->riskPeriod);
    }
}
