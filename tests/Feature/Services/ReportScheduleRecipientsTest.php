<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Mail\ReportGeneratedMail;
use App\Models\Report;
use App\Models\ReportTemplate;
use App\Models\Site;
use App\Services\ReportManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * P2-04: recipient emails must be validated/sanitized on save, and a single bad
 * address must never abort delivery to the rest of the list.
 */
class ReportScheduleRecipientsTest extends TestCase
{
    use RefreshDatabase;

    public function test_save_schedule_drops_invalid_recipient_addresses(): void
    {
        $site = Site::factory()->create();
        $template = ReportTemplate::factory()->create();

        $schedule = app(ReportManagementService::class)->saveSchedule($site, [
            'template_id' => $template->id,
            'is_active' => true,
            'frequency' => 'monthly',
            'day_of_month' => 1,
            'recipient_emails_raw' => 'good@example.com, not-an-email, second@example.com',
            'send_copy_to_admin' => false,
            'email_subject' => null,
            'email_body' => null,
        ]);

        $this->assertSame(
            ['good@example.com', 'second@example.com'],
            $schedule->recipient_emails,
        );
    }

    public function test_send_report_skips_bad_address_and_delivers_to_good_ones(): void
    {
        Mail::fake();

        $site = Site::factory()->create();
        $report = Report::factory()->completed()->create(['site_id' => $site->id]);

        app(ReportManagementService::class)->sendReport($report, [
            'first@example.com',
            'not-an-email',
            'second@example.com',
        ]);

        Mail::assertSent(ReportGeneratedMail::class, 2);
        Mail::assertSent(ReportGeneratedMail::class, fn (ReportGeneratedMail $m) => $m->hasTo('first@example.com'));
        Mail::assertSent(ReportGeneratedMail::class, fn (ReportGeneratedMail $m) => $m->hasTo('second@example.com'));
        Mail::assertNotSent(ReportGeneratedMail::class, fn (ReportGeneratedMail $m) => $m->hasTo('not-an-email'));

        $this->assertEqualsCanonicalizing(
            ['first@example.com', 'second@example.com'],
            $report->fresh()->sent_to,
        );
        $this->assertTrue($report->fresh()->was_sent);
    }
}
