<?php

namespace Tests\Unit\Models;

use App\Models\SecurityIssue;
use App\Models\SecurityScan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesSite;

class SecurityIssueTest extends TestCase
{
    use RefreshDatabase, CreatesSite;

    public function test_active_scope_excludes_fixed_issues(): void
    {
        $site = $this->createSite();
        $scan = SecurityScan::factory()->create(['site_id' => $site->id]);

        $activeIssue = SecurityIssue::factory()->create([
            'site_id' => $site->id,
            'security_scan_id' => $scan->id,
            'is_fixed' => false,
            'is_ignored' => false,
        ]);

        $fixedIssue = SecurityIssue::factory()->create([
            'site_id' => $site->id,
            'security_scan_id' => $scan->id,
            'is_fixed' => true,
            'is_ignored' => false,
        ]);

        $activeIssues = SecurityIssue::active()->pluck('id')->toArray();

        $this->assertContains($activeIssue->id, $activeIssues);
        $this->assertNotContains($fixedIssue->id, $activeIssues);
    }

    public function test_active_scope_excludes_ignored_issues(): void
    {
        $site = $this->createSite();
        $scan = SecurityScan::factory()->create(['site_id' => $site->id]);

        $activeIssue = SecurityIssue::factory()->create([
            'site_id' => $site->id,
            'security_scan_id' => $scan->id,
            'is_fixed' => false,
            'is_ignored' => false,
        ]);

        $ignoredIssue = SecurityIssue::factory()->create([
            'site_id' => $site->id,
            'security_scan_id' => $scan->id,
            'is_fixed' => false,
            'is_ignored' => true,
        ]);

        $activeIssues = SecurityIssue::active()->pluck('id')->toArray();

        $this->assertContains($activeIssue->id, $activeIssues);
        $this->assertNotContains($ignoredIssue->id, $activeIssues);
    }

    public function test_severity_scope_filters_by_level(): void
    {
        $site = $this->createSite();
        $scan = SecurityScan::factory()->create(['site_id' => $site->id]);

        $criticalIssue = SecurityIssue::factory()->create([
            'site_id' => $site->id,
            'security_scan_id' => $scan->id,
            'severity' => 'critical',
        ]);

        $lowIssue = SecurityIssue::factory()->create([
            'site_id' => $site->id,
            'security_scan_id' => $scan->id,
            'severity' => 'low',
        ]);

        $criticalIssues = SecurityIssue::severity('critical')->pluck('id')->toArray();

        $this->assertContains($criticalIssue->id, $criticalIssues);
        $this->assertNotContains($lowIssue->id, $criticalIssues);
    }
}
