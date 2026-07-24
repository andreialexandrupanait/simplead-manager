<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\Audit;
use App\Models\AuditCard;
use App\Models\AuditCheck;
use App\Models\AuditReport;
use App\Services\Audit\PublishAuditReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Faza D6: the public report route (/raport/{slug}).
 */
class PublicAuditReportControllerTest extends TestCase
{
    use RefreshDatabase;

    private function publishedReport(): AuditReport
    {
        $audit = Audit::factory()->create(['url' => 'https://acme.example']);
        $key = (string) AuditCheck::query()->value('key');
        AuditCard::factory()->for($audit)->validation('APROBAT')->create(['title' => 'Recomandare X', 'check_ids' => [$key]]);

        return (new PublishAuditReport)->publish($audit);
    }

    public function test_it_serves_the_report_html_by_slug(): void
    {
        $report = $this->publishedReport();

        $response = $this->get(route('audit-report.public', ['slug' => $report->slug]))
            ->assertOk()
            ->assertSee('Recomandare X');

        $this->assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'));
    }

    public function test_unknown_slug_is_404(): void
    {
        $this->get(route('audit-report.public', ['slug' => 'inexistent']))->assertNotFound();
    }

    public function test_token_gated_report_requires_matching_token(): void
    {
        $report = $this->publishedReport();
        $report->update(['token_required' => true]);

        $this->get(route('audit-report.public', ['slug' => $report->slug]))->assertForbidden();
        $this->get(route('audit-report.public', ['slug' => $report->slug]).'?t=wrong')->assertForbidden();
        $this->get(route('audit-report.public', ['slug' => $report->slug]).'?t='.$report->access_token)->assertOk();
    }
}
