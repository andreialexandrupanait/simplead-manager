<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Report;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * P2-71: single-report and bulk (zip) download must apply ONE authorization rule —
 * a user may download iff they may access the report's site; admins always,
 * cross-tenant never.
 */
class ReportDownloadAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function makeReport(Site $site): Report
    {
        $path = 'reports/report-'.$site->id.'.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 fake report body');

        return Report::factory()->completed()->create([
            'site_id' => $site->id,
            'file_path' => $path,
            'file_name' => 'report.pdf',
        ]);
    }

    private function assertBothPaths(User $user, Site $site, Report $report, int $expected): void
    {
        $single = $this->actingAs($user)->get("/reports/{$report->id}/download");
        $single->assertStatus($expected);

        $bulk = $this->actingAs($user)->get("/sites/{$site->id}/reports/bulk-download?ids={$report->id}");
        $bulk->assertStatus($expected);
    }

    public function test_admin_can_download_via_both_paths(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['role' => UserRole::Manager]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $site = Site::factory()->create(['user_id' => $owner->id]);
        $report = $this->makeReport($site);

        $this->assertBothPaths($admin, $site, $report, 200);
    }

    public function test_owner_can_download_via_both_paths(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['role' => UserRole::Manager]);
        $site = Site::factory()->create(['user_id' => $owner->id]);
        $report = $this->makeReport($site);

        $this->assertBothPaths($owner, $site, $report, 200);
    }

    public function test_client_assigned_non_owner_can_download_via_both_paths(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['role' => UserRole::Manager]);
        $member = User::factory()->create(['role' => UserRole::Manager]);
        $client = Client::factory()->create();
        $member->assignedClients()->attach($client->id);
        $site = Site::factory()->create(['user_id' => $owner->id, 'client_id' => $client->id]);
        $report = $this->makeReport($site);

        $this->assertBothPaths($member, $site, $report, 200);
    }

    public function test_user_without_site_access_is_denied_via_both_paths(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['role' => UserRole::Manager]);
        $intruder = User::factory()->create(['role' => UserRole::Manager]);
        $site = Site::factory()->create(['user_id' => $owner->id]);
        $report = $this->makeReport($site);

        $this->assertBothPaths($intruder, $site, $report, 403);
    }
}
