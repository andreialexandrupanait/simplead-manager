<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Sites\Detail\SiteReports;
use App\Models\ReportSchedule;
use App\Models\ReportTemplate;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SiteReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->site = Site::factory()->for($this->admin)->create();
    }

    // ─── Rendering ────────────────────────────────────────────────────

    #[Test]
    public function user_can_view_reports_tab(): void
    {
        ReportTemplate::factory()->default()->create();

        Livewire::actingAs($this->admin)
            ->test(SiteReports::class, ['site' => $this->site])
            ->assertOk();
    }

    // ─── saveSchedule() ───────────────────────────────────────────────

    #[Test]
    public function user_can_create_report_schedule(): void
    {
        $template = ReportTemplate::factory()->create();

        Livewire::actingAs($this->admin)
            ->test(SiteReports::class, ['site' => $this->site])
            ->call('openScheduleModal')
            ->set('scheduleTemplateId', $template->id)
            ->set('scheduleFrequency', 'monthly')
            ->set('scheduleTime', '09:00')
            ->set('schedulePeriod', 'last_30_days')
            ->set('scheduleActive', true)
            ->call('saveSchedule');

        $this->assertDatabaseHas('report_schedules', [
            'site_id' => $this->site->id,
            'report_template_id' => $template->id,
            'frequency' => 'monthly',
            'is_active' => true,
        ]);
    }

    // ─── toggleScheduleActive ─────────────────────────────────────────

    #[Test]
    public function user_can_toggle_schedule_active(): void
    {
        $template = ReportTemplate::factory()->create();

        $schedule = ReportSchedule::factory()
            ->for($this->site)
            ->for($template, 'reportTemplate')
            ->active()
            ->monthly()
            ->create(['period' => 'last_30_days']);

        // Open the schedule modal (which loads the existing schedule) and toggle it inactive
        Livewire::actingAs($this->admin)
            ->test(SiteReports::class, ['site' => $this->site])
            ->call('openScheduleModal')
            ->set('scheduleActive', false)
            ->call('saveSchedule');

        $this->assertDatabaseHas('report_schedules', [
            'id' => $schedule->id,
            'is_active' => false,
        ]);
    }

    // ─── deleteSchedule() ─────────────────────────────────────────────

    #[Test]
    public function user_can_delete_report_schedule(): void
    {
        $template = ReportTemplate::factory()->create();

        $schedule = ReportSchedule::factory()
            ->for($this->site)
            ->for($template, 'reportTemplate')
            ->monthly()
            ->create(['period' => 'last_30_days']);

        Livewire::actingAs($this->admin)
            ->test(SiteReports::class, ['site' => $this->site])
            ->call('openScheduleModal')
            ->call('deleteSchedule');

        $this->assertDatabaseMissing('report_schedules', ['id' => $schedule->id]);
    }

    // ─── Authorization ────────────────────────────────────────────────

    #[Test]
    public function viewer_cannot_access_other_users_site_reports(): void
    {
        ReportTemplate::factory()->default()->create();

        $viewer = User::factory()->viewer()->create();
        $otherAdmin = User::factory()->admin()->create();
        $otherSite = Site::factory()->for($otherAdmin)->create();

        Livewire::actingAs($viewer)
            ->test(SiteReports::class, ['site' => $otherSite])
            ->assertForbidden();
    }
}
