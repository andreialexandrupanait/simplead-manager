<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Sites\Detail\SiteSeoAudit;
use App\Models\SeoAudit;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P2-18: broken-link suggestions must read from the LATEST COMPLETED audit.
 * PostgreSQL `ORDER BY scanned_at DESC` sorts NULLs FIRST, so a completed-but-
 * undated audit (or an in-progress one) could win over a genuinely newer,
 * finished audit. The latestCompleted scope forces NULLS LAST.
 */
class SiteSeoAuditLatestAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_dated_completed_audit_wins_over_null_dated_one(): void
    {
        $site = Site::factory()->create();

        // Created first, so it has the LOWER id — the id tie-break must not
        // rescue it; NULLS LAST must exclude it in favour of the dated audit.
        $nullDated = SeoAudit::create(['site_id' => $site->id, 'status' => 'completed', 'scanned_at' => null]);
        $dated = SeoAudit::create(['site_id' => $site->id, 'status' => 'completed', 'scanned_at' => now()]);

        $selected = $site->seoAudits()->latestCompleted()->first();

        $this->assertSame($dated->id, $selected->id);
        $this->assertNotSame($nullDated->id, $selected->id);
    }

    public function test_newer_completed_audit_is_selected_over_older(): void
    {
        $site = Site::factory()->create();

        $older = SeoAudit::create(['site_id' => $site->id, 'status' => 'completed', 'scanned_at' => now()->subDays(3)]);
        $newer = SeoAudit::create(['site_id' => $site->id, 'status' => 'completed', 'scanned_at' => now()]);

        $this->assertSame($newer->id, $site->seoAudits()->latestCompleted()->first()->id);
        $this->assertNotNull($older);
    }

    public function test_in_progress_audit_is_never_selected_over_a_completed_one(): void
    {
        $site = Site::factory()->create();

        $completed = SeoAudit::create(['site_id' => $site->id, 'status' => 'completed', 'scanned_at' => now()]);
        // In-progress audit created afterwards (higher id, null scanned_at).
        SeoAudit::create(['site_id' => $site->id, 'status' => 'crawling', 'scanned_at' => null]);

        $this->assertSame($completed->id, $site->seoAudits()->latestCompleted()->first()->id);
    }

    public function test_component_reads_suggestions_from_the_newest_completed_audit(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $site = Site::factory()->create(['user_id' => $manager->id, 'is_connected' => true]);

        SeoAudit::create(['site_id' => $site->id, 'status' => 'completed', 'scanned_at' => now()->subDays(2)]);
        $newer = SeoAudit::create(['site_id' => $site->id, 'status' => 'completed', 'scanned_at' => now()]);
        // A later, still-running audit must not hijack the display.
        SeoAudit::create(['site_id' => $site->id, 'status' => 'crawling', 'scanned_at' => null]);

        $component = Livewire::actingAs($manager)->test(SiteSeoAudit::class, ['site' => $site]);

        $this->assertSame($newer->id, $component->instance()->latestCompletedAudit()->id);
    }
}
