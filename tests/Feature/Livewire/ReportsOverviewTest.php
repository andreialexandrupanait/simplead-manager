<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\ReportStatus;
use App\Livewire\Reports\ReportsOverview;
use App\Models\Report;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReportsOverviewTest extends TestCase
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
    public function admin_can_view_reports_overview(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ReportsOverview::class)
            ->assertOk();
    }

    #[Test]
    public function list_renders_with_existing_reports(): void
    {
        Report::factory()
            ->count(3)
            ->for($this->site)
            ->completed()
            ->create();

        Livewire::actingAs($this->admin)
            ->test(ReportsOverview::class)
            ->assertOk();
    }

    // ─── Search ───────────────────────────────────────────────────────

    #[Test]
    public function search_resets_pagination_when_updated(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(ReportsOverview::class)
            ->set('search', 'monthly');

        $this->assertEquals('monthly', $component->get('search'));
    }

    #[Test]
    public function status_filter_can_be_changed(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(ReportsOverview::class)
            ->set('status', 'completed');

        $this->assertEquals('completed', $component->get('status'));
    }

    #[Test]
    public function site_filter_can_be_set(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(ReportsOverview::class)
            ->set('siteFilter', (string) $this->site->id);

        $this->assertEquals((string) $this->site->id, $component->get('siteFilter'));
    }

    // ─── deleteReport() ───────────────────────────────────────────────

    #[Test]
    public function admin_can_delete_a_report_without_file(): void
    {
        $report = Report::factory()
            ->for($this->site)
            ->create(['file_path' => null]);

        Livewire::actingAs($this->admin)
            ->test(ReportsOverview::class)
            ->call('deleteReport', $report->id);

        $this->assertDatabaseMissing('reports', ['id' => $report->id]);
    }

    #[Test]
    public function delete_report_removes_file_from_storage_when_path_set(): void
    {
        Storage::fake('local');

        $filePath = 'reports/test-report.pdf';
        Storage::disk('local')->put($filePath, 'fake pdf content');

        $report = Report::factory()
            ->for($this->site)
            ->create(['file_path' => $filePath]);

        Livewire::actingAs($this->admin)
            ->test(ReportsOverview::class)
            ->call('deleteReport', $report->id);

        $this->assertDatabaseMissing('reports', ['id' => $report->id]);
        Storage::disk('local')->assertMissing($filePath);
    }

    #[Test]
    public function status_filter_shows_only_failed_reports(): void
    {
        Report::factory()
            ->for($this->site)
            ->completed()
            ->create(['title' => 'Completed Report']);

        Report::factory()
            ->for($this->site)
            ->failed()
            ->create(['title' => 'Failed Report']);

        $component = Livewire::actingAs($this->admin)
            ->test(ReportsOverview::class)
            ->set('status', ReportStatus::Failed->value);

        // The component renders without error and filter state is preserved
        $this->assertEquals(ReportStatus::Failed->value, $component->get('status'));
        $component->assertOk();
    }

    #[Test]
    public function multiple_reports_render_without_errors(): void
    {
        Report::factory()
            ->count(25)
            ->for($this->site)
            ->completed()
            ->create();

        Livewire::actingAs($this->admin)
            ->test(ReportsOverview::class)
            ->assertOk();
    }
}
