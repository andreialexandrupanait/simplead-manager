<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Enums\AuditStatus;
use App\Models\Audit;
use App\Models\AuditCard;
use App\Models\AuditCheck;
use App\Services\Audit\PublishAuditReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Faza D6: publishing (and re-publishing) the public report snapshot.
 */
class PublishAuditReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_publish_creates_a_report_and_marks_the_audit_published(): void
    {
        $audit = Audit::factory()->create(['status' => AuditStatus::InValidare, 'url' => 'https://acme.example']);
        $key = (string) AuditCheck::query()->value('key');
        AuditCard::factory()->for($audit)->validation('APROBAT')->create(['title' => 'Fix titluri', 'check_ids' => [$key]]);

        $report = (new PublishAuditReport)->publish($audit);

        $this->assertNotEmpty($report->slug);
        $this->assertSame(1, $report->version);
        $this->assertStringContainsString('Fix titluri', (string) $report->html);
        $this->assertSame(AuditStatus::Publicat, $audit->fresh()->status);
    }

    public function test_republish_keeps_the_slug_and_bumps_the_version(): void
    {
        $audit = Audit::factory()->create(['url' => 'https://acme.example']);
        $key = (string) AuditCheck::query()->value('key');
        AuditCard::factory()->for($audit)->validation('APROBAT')->create(['check_ids' => [$key]]);

        $first = (new PublishAuditReport)->publish($audit);
        $second = (new PublishAuditReport)->publish($audit->fresh());

        $this->assertSame($first->slug, $second->slug);
        $this->assertSame($first->access_token, $second->access_token);
        $this->assertSame(2, $second->version);
        $this->assertSame(1, \App\Models\AuditReport::query()->where('audit_id', $audit->id)->count());
    }
}
