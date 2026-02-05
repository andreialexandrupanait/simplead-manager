<?php

namespace Tests\Unit\Models;

use App\Models\SecurityIssue;
use App\Models\SecurityScan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesSite;

class SecurityScanTest extends TestCase
{
    use RefreshDatabase, CreatesSite;

    public function test_factory_creates_valid_record(): void
    {
        $site = $this->createSite();

        $scan = SecurityScan::factory()->create(['site_id' => $site->id]);

        $this->assertDatabaseHas('security_scans', ['id' => $scan->id]);
        $this->assertNotNull($scan->score);
        $this->assertNotNull($scan->scanned_at);
    }

    public function test_belongs_to_site_relationship(): void
    {
        $site = $this->createSite();

        $scan = SecurityScan::factory()->create(['site_id' => $site->id]);

        $this->assertEquals($site->id, $scan->site->id);
        $this->assertInstanceOf(\App\Models\Site::class, $scan->site);
    }

    public function test_has_many_security_issues_relationship(): void
    {
        $site = $this->createSite();

        $scan = SecurityScan::factory()->create(['site_id' => $site->id]);

        SecurityIssue::factory()->count(3)->create([
            'site_id' => $site->id,
            'security_scan_id' => $scan->id,
        ]);

        $this->assertCount(3, $scan->issues);
        $this->assertInstanceOf(SecurityIssue::class, $scan->issues->first());
    }

    public function test_score_is_cast_to_integer(): void
    {
        $site = $this->createSite();

        $scan = SecurityScan::factory()->create([
            'site_id' => $site->id,
            'score' => 85,
        ]);

        $freshScan = SecurityScan::find($scan->id);

        $this->assertIsInt($freshScan->score);
        $this->assertEquals(85, $freshScan->score);
    }

    public function test_scores_breakdown_is_cast_to_array(): void
    {
        $site = $this->createSite();

        $breakdown = [
            'headers' => 12,
            'ssl' => 15,
            'core_integrity' => 10,
            'recommendations' => 18,
            'vulnerabilities' => 20,
            'firewall' => 8,
            'updates' => 5,
        ];

        $scan = SecurityScan::factory()->create([
            'site_id' => $site->id,
            'scores_breakdown' => $breakdown,
        ]);

        $freshScan = SecurityScan::find($scan->id);

        $this->assertIsArray($freshScan->scores_breakdown);
        $this->assertEquals($breakdown, $freshScan->scores_breakdown);
    }
}
