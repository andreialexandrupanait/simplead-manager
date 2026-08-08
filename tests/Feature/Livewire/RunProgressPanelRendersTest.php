<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\BackupStatus;
use App\Enums\UserRole;
use App\Livewire\Sites\Detail\SiteBackups;
use App\Models\Backup;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The live progress panels render, for a backup and for a restore.
 *
 * Written because the two panels were ninety lines of duplicated markup that had already drifted
 * apart, and folding them into one component is exactly the kind of change that passes every
 * existing test and then fails on screen: nothing in the suite rendered either panel. A component
 * with a mistyped prop name, or an x-ref that no longer resolves, would have shipped.
 */
class RunProgressPanelRendersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::spy();
        Queue::fake();
        Http::fake();

        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->site = Site::factory()->create(['user_id' => $this->admin->id]);
    }

    public function test_a_running_backup_shows_its_progress_panel(): void
    {
        $backup = Backup::factory()->create([
            'site_id' => $this->site->id,
            'status' => BackupStatus::InProgress,
            'type' => 'full',
            'started_at' => now(),
            'progress_percent' => 40,
            'progress_message' => 'Uploading files',
        ]);

        Livewire::actingAs($this->admin)
            ->test(SiteBackups::class, ['site' => $this->site])
            ->set('trackingBackupId', $backup->id)
            ->assertOk()
            ->assertSee('Full Backup')
            ->assertSee('in Progress')
            // The cancel button is the one control on this panel that does something
            // irreversible-ish, and it is offered only while the run is live.
            ->assertSeeHtml('wire:click="cancelBackup"');
    }

    /**
     * A restore offers no cancel button. Stopping half-way leaves the live site in neither state,
     * so the panel deliberately does not ask a question whose safe answer is always "no".
     */
    public function test_a_running_restore_shows_a_panel_without_a_cancel_button(): void
    {
        Backup::factory()->create([
            'site_id' => $this->site->id,
            'status' => BackupStatus::Completed,
            'restore_status' => BackupStatus::InProgress,
            'restore_progress_percent' => 20,
            'restore_stage' => 'restoring_database',
        ]);

        $html = Livewire::actingAs($this->admin)
            ->test(SiteBackups::class, ['site' => $this->site])
            ->assertOk()
            ->assertSee('Restore')
            ->html();

        $this->assertStringNotContainsString('cancelRestore', $html);
    }

    /** A site with nothing running shows no panel at all, rather than an empty one. */
    public function test_an_idle_site_shows_no_progress_panel(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SiteBackups::class, ['site' => $this->site])
            ->assertOk()
            ->assertDontSee('in Progress');
    }
}
