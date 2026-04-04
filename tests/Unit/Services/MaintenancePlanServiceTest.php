<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\MaintenancePlanService;
use PHPUnit\Framework\TestCase;

class MaintenancePlanServiceTest extends TestCase
{
    public function test_count_settings_returns_zero_for_null(): void
    {
        $this->assertSame(0, MaintenancePlanService::countSettings(null));
    }

    public function test_count_settings_returns_zero_for_empty_array(): void
    {
        $this->assertSame(0, MaintenancePlanService::countSettings([]));
    }

    public function test_count_settings_counts_all_keys_across_categories(): void
    {
        $settings = [
            'firewall' => [
                'block_xmlrpc' => ['value' => true, 'enabled' => true],
                'block_author_scans' => ['value' => true, 'enabled' => false],
            ],
            'login' => [
                'limit_attempts' => ['value' => 5, 'enabled' => true],
            ],
        ];

        $this->assertSame(3, MaintenancePlanService::countSettings($settings));
    }

    public function test_count_settings_ignores_non_array_categories(): void
    {
        $settings = [
            'firewall' => [
                'block_xmlrpc' => ['value' => true, 'enabled' => true],
            ],
            'invalid' => 'not_an_array',
        ];

        $this->assertSame(1, MaintenancePlanService::countSettings($settings));
    }

    public function test_count_enabled_settings_returns_zero_for_null(): void
    {
        $this->assertSame(0, MaintenancePlanService::countEnabledSettings(null));
    }

    public function test_count_enabled_settings_returns_zero_for_empty_array(): void
    {
        $this->assertSame(0, MaintenancePlanService::countEnabledSettings([]));
    }

    public function test_count_enabled_settings_counts_only_enabled(): void
    {
        $settings = [
            'firewall' => [
                'block_xmlrpc' => ['value' => true, 'enabled' => true],
                'block_author_scans' => ['value' => true, 'enabled' => false],
            ],
            'login' => [
                'limit_attempts' => ['value' => 5, 'enabled' => true],
                'two_factor' => ['value' => true, 'enabled' => false],
            ],
        ];

        $this->assertSame(2, MaintenancePlanService::countEnabledSettings($settings));
    }

    public function test_count_enabled_settings_handles_missing_enabled_key(): void
    {
        $settings = [
            'category' => [
                'setting_without_enabled' => ['value' => true],
                'setting_with_enabled' => ['value' => true, 'enabled' => true],
            ],
        ];

        $this->assertSame(1, MaintenancePlanService::countEnabledSettings($settings));
    }

    public function test_count_enabled_settings_ignores_non_array_categories(): void
    {
        $settings = [
            'firewall' => [
                'block_xmlrpc' => ['value' => true, 'enabled' => true],
            ],
            'invalid' => 'not_an_array',
        ];

        $this->assertSame(1, MaintenancePlanService::countEnabledSettings($settings));
    }
}
