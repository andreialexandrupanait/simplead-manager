<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Enums\AuditTeam;
use App\Models\Audit;
use App\Models\AuditCheck;
use App\Models\Prospect;
use App\Models\Site;
use Database\Seeders\AuditChecksSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Faza D (D1): the audit module schema + the seed of the 82 methodology checks
 * from checks.js. The migration seeds the checks automatically.
 */
class AuditModuleSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_exactly_82_checks_in_the_five_methodology_sections(): void
    {
        $this->assertSame(82, AuditCheck::count());

        $bySection = AuditCheck::query()
            ->selectRaw('section_key, count(*) as n')
            ->groupBy('section_key')
            ->pluck('n', 'section_key')
            ->all();

        $this->assertEqualsCanonicalizing([
            'seo-onsite' => 44,
            'tehnic' => 10,
            'seo-offsite' => 5,
            'cro' => 13,
            'llm-aeo-geo' => 10,
        ], array_map('intval', $bySection));
    }

    public function test_a_seeded_check_carries_its_full_methodology_shape(): void
    {
        $check = AuditCheck::where('key', '2.1.1')->firstOrFail();

        $this->assertSame('seo-onsite', $check->section_key);
        $this->assertSame('2.1', $check->subsection_id);
        $this->assertSame(AuditTeam::Dev, $check->team);
        $this->assertNotEmpty($check->question);
        $this->assertNotEmpty($check->sources); // normalized to an array
        $this->assertSame('sf_export', $check->sources[0]['type'] ?? null);
        $this->assertArrayHasKey('seo', $check->lenses ?? []);
        $this->assertNotEmpty($check->recommendation_template);
    }

    public function test_the_seeder_is_idempotent(): void
    {
        (new AuditChecksSeeder)->run();
        (new AuditChecksSeeder)->run();

        $this->assertSame(82, AuditCheck::count());
        $this->assertSame(1, AuditCheck::where('key', '2.1.1')->count());
    }

    public function test_an_audit_must_target_exactly_one_of_site_or_prospect(): void
    {
        $site = Site::factory()->create();
        $prospect = Prospect::factory()->create();

        // Both set → CHECK constraint rejects it.
        try {
            Audit::factory()->create(['site_id' => $site->id, 'prospect_id' => $prospect->id]);
            $this->fail('An audit with both a site and a prospect must be rejected.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        // Neither set → also rejected.
        try {
            Audit::factory()->create(['site_id' => null, 'prospect_id' => null]);
            $this->fail('An audit with neither a site nor a prospect must be rejected.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    public function test_audit_target_resolves_the_site_or_the_prospect(): void
    {
        Queue::fake(); // suppress FetchSiteFavicon on Site::created

        $prospectAudit = Audit::factory()->create();
        $this->assertInstanceOf(Prospect::class, $prospectAudit->target());

        $site = Site::factory()->create();
        $siteAudit = Audit::factory()->forSite($site)->create();
        $this->assertInstanceOf(Site::class, $siteAudit->target());
        $this->assertNull($siteAudit->prospect_id);
    }
}
