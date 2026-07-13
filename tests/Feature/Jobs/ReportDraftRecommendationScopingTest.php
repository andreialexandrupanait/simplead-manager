<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Models\Report;
use App\Models\ReportRecommendation;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P3-22: draft recommendations were linked by site_id alone, so a report
 * generating concurrently for the same site claimed EVERY unlinked draft —
 * stealing the recommendations meant for another report. Linking must be
 * scoped to the drafts a report captured at its start.
 */
class ReportDraftRecommendationScopingTest extends TestCase
{
    use RefreshDatabase;

    private function draft(Site $site): ReportRecommendation
    {
        return ReportRecommendation::create([
            'site_id' => $site->id,
            'priority' => 'high',
            'category' => 'technical',
            'title' => 'Fix something',
            'description' => 'Details',
            'is_auto_generated' => true,
            'is_included' => true,
        ]);
    }

    public function test_a_report_only_links_the_drafts_it_captured(): void
    {
        $site = Site::factory()->create();
        $reportA = Report::factory()->create(['site_id' => $site->id]);
        $reportB = Report::factory()->create(['site_id' => $site->id]);

        // Drafts present when report A starts.
        $d1 = $this->draft($site);
        $d2 = $this->draft($site);
        $snapshotA = ReportRecommendation::forSite($site->id)->drafts()->pluck('id')->all();

        // Report B creates its own draft AFTER A's snapshot but before A links.
        $d3 = $this->draft($site);

        // Report A links only its captured set.
        ReportRecommendation::linkDraftsToReport($snapshotA, $reportA->id);

        // A must NOT have stolen B's later draft.
        $this->assertNull($d3->refresh()->report_id);
        $this->assertSame($reportA->id, $d1->refresh()->report_id);
        $this->assertSame($reportA->id, $d2->refresh()->report_id);

        // Report B then claims what remains — only its own draft.
        $snapshotB = ReportRecommendation::forSite($site->id)->drafts()->pluck('id')->all();
        ReportRecommendation::linkDraftsToReport($snapshotB, $reportB->id);

        $this->assertSame($reportB->id, $d3->refresh()->report_id);
        // A's recommendations stayed with A.
        $this->assertSame($reportA->id, $d1->refresh()->report_id);
    }
}
