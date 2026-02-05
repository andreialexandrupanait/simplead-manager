<?php

namespace Tests\Unit\Models;

use App\Models\StatusPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatusPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_overall_status_returns_operational_for_empty_page(): void
    {
        $statusPage = StatusPage::factory()->create();

        // No StatusPageSites attached
        $this->assertEquals('operational', $statusPage->overall_status);
    }

    public function test_public_url_returns_correct_url_format(): void
    {
        $statusPage = StatusPage::factory()->create([
            'slug' => 'my-company-status',
        ]);

        $this->assertEquals(url('/status/my-company-status'), $statusPage->public_url);
        $this->assertStringContainsString('/status/my-company-status', $statusPage->public_url);
    }

    public function test_factory_creates_valid_record(): void
    {
        $statusPage = StatusPage::factory()->create();

        $this->assertDatabaseHas('status_pages', ['id' => $statusPage->id]);
        $this->assertNotNull($statusPage->slug);
        $this->assertNotNull($statusPage->title);
        $this->assertTrue($statusPage->is_public);
    }
}
