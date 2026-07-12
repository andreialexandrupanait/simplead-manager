<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\SeoAudit;
use App\Models\SeoIssue;
use App\Models\SeoPage;
use App\Models\Site;
use App\Services\SeoAudit\ScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P2-17: per-page SEO penalties must be AVERAGED, not summed unbounded. A site
 * with many pages must not have its category scores driven to zero purely by
 * page count.
 */
class SeoScoringNormalizationTest extends TestCase
{
    use RefreshDatabase;

    private function makeAudit(int $pageCount, string $severity = 'high', bool $withIssues = true): SeoAudit
    {
        $site = Site::factory()->create();
        $audit = SeoAudit::create(['site_id' => $site->id, 'status' => 'completed', 'scanned_at' => now()]);

        for ($i = 0; $i < $pageCount; $i++) {
            $url = "https://acme.com/p{$i}";
            SeoPage::create([
                'seo_audit_id' => $audit->id,
                'site_id' => $site->id,
                'url' => $url,
                'url_hash' => md5($url),
                'status_code' => 200,
            ]);

            if ($withIssues) {
                SeoIssue::create([
                    'seo_audit_id' => $audit->id,
                    'site_id' => $site->id,
                    'category' => 'on_page',
                    'severity' => $severity,
                    'title' => 'Missing meta description',
                    'url' => $url,
                ]);
            }
        }

        return $audit;
    }

    public function test_category_score_is_independent_of_page_count(): void
    {
        $scoring = app(ScoringService::class);

        $small = $scoring->calculateScores($this->makeAudit(5));
        $large = $scoring->calculateScores($this->makeAudit(50));

        // Same average per-page quality → same category score regardless of size.
        $this->assertSame($small['categories']['on_page'], $large['categories']['on_page']);
        $this->assertGreaterThan(0, $small['categories']['on_page'], 'page count must not saturate the score to zero');
    }

    public function test_all_perfect_pages_score_100(): void
    {
        $scores = app(ScoringService::class)->calculateScores($this->makeAudit(20, withIssues: false));

        $this->assertSame(100, $scores['overall']);
        $this->assertSame(100, $scores['categories']['on_page']);
        $this->assertSame(100, $scores['categories']['technical']);
    }

    public function test_many_slightly_imperfect_pages_do_not_saturate_to_zero(): void
    {
        // 100 pages, each with a single critical (penalty 15) on-page issue.
        // Old behaviour: 100 × 15 = 1500 penalty → score 0. New: averaged → 85.
        $scores = app(ScoringService::class)->calculateScores($this->makeAudit(100, 'critical'));

        $this->assertSame(85, $scores['categories']['on_page']);
    }
}
