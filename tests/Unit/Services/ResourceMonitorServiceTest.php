<?php

namespace Tests\Unit\Services;

use App\Models\ResourceCheck;
use App\Services\ResourceMonitorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Traits\CreatesSite;
use Tests\Traits\MocksWordPressApi;

class ResourceMonitorServiceTest extends TestCase
{
    use RefreshDatabase, CreatesSite, MocksWordPressApi;

    public function test_fetch_and_store_creates_resource_check_record(): void
    {
        $this->fakeWordPressApi();
        $site = $this->createSite();

        $service = new ResourceMonitorService();
        $check = $service->fetchAndStore($site);

        $this->assertInstanceOf(ResourceCheck::class, $check);
        $this->assertDatabaseHas('resource_checks', [
            'site_id' => $site->id,
        ]);
    }

    public function test_fetch_and_store_stores_data_from_api(): void
    {
        $this->fakeWordPressApi();
        $site = $this->createSite();

        $service = new ResourceMonitorService();
        $check = $service->fetchAndStore($site);

        $this->assertEquals(25.5, (float) $check->cpu_usage);
        $this->assertEquals(2000000000, $check->memory_used);
        $this->assertEquals(8000000000, $check->memory_total);
        $this->assertTrue($check->is_available);
    }

    public function test_check_thresholds_returns_warning_for_disk_over_80_percent(): void
    {
        $site = $this->createSite();

        $check = ResourceCheck::create([
            'site_id' => $site->id,
            'disk_percentage' => 85,
            'memory_percentage' => 50,
            'cpu_usage' => 30,
            'is_available' => true,
            'checked_at' => now(),
        ]);

        $service = new ResourceMonitorService();
        $violations = $service->checkThresholds($check);

        $this->assertContains('disk_space_warning', $violations);
        $this->assertNotContains('disk_space_critical', $violations);
    }

    public function test_check_thresholds_returns_critical_for_disk_over_90_percent(): void
    {
        $site = $this->createSite();

        $check = ResourceCheck::create([
            'site_id' => $site->id,
            'disk_percentage' => 95,
            'memory_percentage' => 50,
            'cpu_usage' => 30,
            'is_available' => true,
            'checked_at' => now(),
        ]);

        $service = new ResourceMonitorService();
        $violations = $service->checkThresholds($check);

        $this->assertContains('disk_space_critical', $violations);
    }

    public function test_check_thresholds_returns_warning_for_memory_over_80_percent(): void
    {
        $site = $this->createSite();

        $check = ResourceCheck::create([
            'site_id' => $site->id,
            'disk_percentage' => 50,
            'memory_percentage' => 85,
            'cpu_usage' => 30,
            'is_available' => true,
            'checked_at' => now(),
        ]);

        $service = new ResourceMonitorService();
        $violations = $service->checkThresholds($check);

        $this->assertContains('memory_warning', $violations);
    }

    public function test_check_thresholds_returns_critical_for_cpu_over_90_percent(): void
    {
        $site = $this->createSite();

        $check = ResourceCheck::create([
            'site_id' => $site->id,
            'disk_percentage' => 50,
            'memory_percentage' => 50,
            'cpu_usage' => 95,
            'is_available' => true,
            'checked_at' => now(),
        ]);

        $service = new ResourceMonitorService();
        $violations = $service->checkThresholds($check);

        $this->assertContains('cpu_warning', $violations);
    }

    public function test_get_history_returns_checks_within_time_range(): void
    {
        $site = $this->createSite();

        // Create a recent check
        ResourceCheck::create([
            'site_id' => $site->id,
            'cpu_usage' => 25,
            'is_available' => true,
            'checked_at' => now()->subDays(5),
        ]);

        // Create an old check outside the default 30-day range
        ResourceCheck::create([
            'site_id' => $site->id,
            'cpu_usage' => 40,
            'is_available' => true,
            'checked_at' => now()->subDays(60),
        ]);

        $service = new ResourceMonitorService();
        $history = $service->getHistory($site, 30);

        $this->assertCount(1, $history);
        $this->assertEquals(25, (float) $history->first()->cpu_usage);
    }

    public function test_fetch_and_store_marks_unavailable_when_api_fails(): void
    {
        $site = $this->createSite();

        // Fake the API to return a server error
        Http::fake([
            '*/wp-json/simplead/v1/server-resources' => Http::response(null, 500),
            '*/wp-json/simplead/v1/*' => Http::response([]),
        ]);

        $service = new ResourceMonitorService();
        $check = $service->fetchAndStore($site);

        $this->assertFalse($check->is_available);
    }
}
