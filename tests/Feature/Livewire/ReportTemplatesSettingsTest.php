<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Settings\ReportTemplatesSettings;
use App\Models\ReportSchedule;
use App\Models\ReportTemplate;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReportTemplatesSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    // ─── Rendering ────────────────────────────────────────────────────

    #[Test]
    public function admin_can_view_report_templates_settings(): void
    {
        ReportTemplate::factory()->count(3)->create();

        Livewire::actingAs($this->admin)
            ->test(ReportTemplatesSettings::class)
            ->assertOk();
    }

    // ─── saveTemplate() — create ──────────────────────────────────────

    #[Test]
    public function admin_can_create_report_template(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ReportTemplatesSettings::class)
            ->call('openCreateForm')
            ->set('name', 'Monthly Report')
            ->set('description', 'A monthly report template.')
            ->set('sections', ['overview', 'uptime', 'backups'])
            ->set('primary_color', '#3b82f6')
            ->call('saveTemplate')
            ->assertDispatched('close-modal-template-form');

        $this->assertDatabaseHas('report_templates', [
            'name' => 'Monthly Report',
            'description' => 'A monthly report template.',
        ]);
    }

    // ─── saveTemplate() — update ──────────────────────────────────────

    #[Test]
    public function admin_can_update_existing_template(): void
    {
        $template = ReportTemplate::factory()->create(['name' => 'Old Name']);

        Livewire::actingAs($this->admin)
            ->test(ReportTemplatesSettings::class)
            ->call('editTemplate', $template->id)
            ->assertDispatched('open-modal-template-form')
            ->set('name', 'Updated Name')
            ->set('sections', ['overview', 'uptime'])
            ->call('saveTemplate')
            ->assertDispatched('close-modal-template-form');

        $this->assertDatabaseHas('report_templates', [
            'id' => $template->id,
            'name' => 'Updated Name',
        ]);
    }

    // ─── duplicateTemplate() ──────────────────────────────────────────

    #[Test]
    public function admin_can_duplicate_template(): void
    {
        $template = ReportTemplate::factory()->create(['name' => 'Original Template']);

        Livewire::actingAs($this->admin)
            ->test(ReportTemplatesSettings::class)
            ->call('duplicateTemplate', $template->id);

        $this->assertDatabaseHas('report_templates', [
            'name' => 'Original Template (Copy)',
            'is_default' => false,
        ]);

        // Original must still exist
        $this->assertDatabaseHas('report_templates', ['id' => $template->id]);
    }

    // ─── deleteTemplate() ─────────────────────────────────────────────

    #[Test]
    public function admin_can_delete_template_without_schedules(): void
    {
        $template = ReportTemplate::factory()->create();

        Livewire::actingAs($this->admin)
            ->test(ReportTemplatesSettings::class)
            ->call('deleteTemplate', $template->id);

        $this->assertDatabaseMissing('report_templates', ['id' => $template->id]);
    }

    #[Test]
    public function admin_cannot_delete_template_with_active_schedules(): void
    {
        $template = ReportTemplate::factory()->create();
        $site = Site::factory()->for($this->admin)->create();

        ReportSchedule::factory()
            ->for($site)
            ->for($template, 'reportTemplate')
            ->active()
            ->create(['period' => 'last_30_days']);

        Livewire::actingAs($this->admin)
            ->test(ReportTemplatesSettings::class)
            ->call('deleteTemplate', $template->id);

        // Template must still exist
        $this->assertDatabaseHas('report_templates', ['id' => $template->id]);
    }

    // ─── setDefault() ─────────────────────────────────────────────────

    #[Test]
    public function admin_can_set_template_as_default(): void
    {
        $existing = ReportTemplate::factory()->default()->create();
        $newDefault = ReportTemplate::factory()->create(['is_default' => false]);

        Livewire::actingAs($this->admin)
            ->test(ReportTemplatesSettings::class)
            ->call('setDefault', $newDefault->id);

        $this->assertDatabaseHas('report_templates', [
            'id' => $newDefault->id,
            'is_default' => true,
        ]);

        // The previous default must no longer be default
        $this->assertDatabaseHas('report_templates', [
            'id' => $existing->id,
            'is_default' => false,
        ]);
    }

    // ─── openCreateForm / cancelForm ──────────────────────────────────

    #[Test]
    public function open_create_form_dispatches_modal_event(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ReportTemplatesSettings::class)
            ->call('openCreateForm')
            ->assertDispatched('open-modal-template-form');
    }

    #[Test]
    public function cancel_form_dispatches_close_event_and_resets_state(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ReportTemplatesSettings::class)
            ->set('name', 'Unsaved Name')
            ->call('cancelForm')
            ->assertDispatched('close-modal-template-form')
            ->assertSet('name', '');
    }

    // ─── Validation ───────────────────────────────────────────────────

    #[Test]
    public function save_template_requires_name(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ReportTemplatesSettings::class)
            ->call('openCreateForm')
            ->set('name', '')
            ->set('sections', ['overview'])
            ->call('saveTemplate')
            ->assertHasErrors(['name' => 'required']);
    }

    #[Test]
    public function save_template_requires_at_least_one_section(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ReportTemplatesSettings::class)
            ->call('openCreateForm')
            ->set('name', 'Valid Name')
            ->set('sections', [])
            ->call('saveTemplate')
            ->assertHasErrors(['sections']);
    }
}
