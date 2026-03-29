<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Jobs\CheckDatabaseHealthJob;
use App\Livewire\Sites\Detail\SiteDatabaseCleanup;
use App\Models\DatabaseCleanup;
use App\Models\DatabaseCleanupConfig;
use App\Models\Site;
use App\Models\User;
use App\Services\DatabaseCleanupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SiteDatabaseCleanupTest extends TestCase
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
    public function user_can_view_database_cleanup_page(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SiteDatabaseCleanup::class, ['site' => $this->site])
            ->assertOk();
    }

    #[Test]
    public function page_shows_module_inactive_when_no_config_exists(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(SiteDatabaseCleanup::class, ['site' => $this->site]);

        $this->assertFalse($component->instance()->isModuleActive);
    }

    // ─── activateModule() ─────────────────────────────────────────────

    #[Test]
    public function user_can_activate_database_cleanup_module(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SiteDatabaseCleanup::class, ['site' => $this->site])
            ->call('activateModule');

        $this->assertDatabaseHas('database_cleanup_configs', [
            'site_id' => $this->site->id,
            'is_enabled' => true,
        ]);
    }

    // ─── refreshHealth() ──────────────────────────────────────────────

    #[Test]
    public function refresh_health_dispatches_check_database_health_job(): void
    {
        Queue::fake();

        Livewire::actingAs($this->admin)
            ->test(SiteDatabaseCleanup::class, ['site' => $this->site])
            ->call('refreshHealth');

        Queue::assertPushed(CheckDatabaseHealthJob::class, function (CheckDatabaseHealthJob $job) {
            return $job->site->id === $this->site->id;
        });
    }

    // ─── confirmCleanup() ─────────────────────────────────────────────

    #[Test]
    public function confirm_cleanup_dispatches_modal_event(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SiteDatabaseCleanup::class, ['site' => $this->site])
            ->call('confirmCleanup')
            ->assertDispatched('open-modal-confirm-cleanup');
    }

    #[Test]
    public function confirm_cleanup_sets_show_confirmation_flag(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(SiteDatabaseCleanup::class, ['site' => $this->site])
            ->call('confirmCleanup');

        $this->assertTrue($component->get('showConfirmation'));
    }

    // ─── runCleanup() ─────────────────────────────────────────────────

    #[Test]
    public function run_cleanup_calls_service_and_creates_cleanup_record(): void
    {
        $mockCleanup = DatabaseCleanup::make([
            'site_id' => $this->site->id,
            'revisions_deleted' => 10,
            'auto_drafts_deleted' => 0,
            'trash_posts_deleted' => 5,
            'spam_comments_deleted' => 0,
            'trash_comments_deleted' => 0,
            'transients_deleted' => 20,
            'orphaned_meta_deleted' => 0,
            'space_saved' => 1024 * 1024,
            'status' => 'completed',
            'cleaned_at' => now(),
        ]);
        $mockCleanup->total_deleted = 35;
        $mockCleanup->formatted_space_saved = '1.00 MB';

        $mockService = Mockery::mock(DatabaseCleanupService::class);
        $mockService->shouldReceive('run')
            ->once()
            ->with(Mockery::on(fn ($s) => $s->id === $this->site->id), Mockery::any())
            ->andReturn($mockCleanup);

        $this->app->instance(DatabaseCleanupService::class, $mockService);

        $component = Livewire::actingAs($this->admin)
            ->test(SiteDatabaseCleanup::class, ['site' => $this->site])
            ->call('runCleanup');

        $this->assertFalse($component->get('showConfirmation'));
    }

    #[Test]
    public function run_cleanup_respects_selected_cleanup_options(): void
    {
        $capturedOptions = null;

        $mockCleanup = DatabaseCleanup::make([
            'site_id' => $this->site->id,
            'revisions_deleted' => 0,
            'auto_drafts_deleted' => 0,
            'trash_posts_deleted' => 0,
            'spam_comments_deleted' => 0,
            'trash_comments_deleted' => 0,
            'transients_deleted' => 5,
            'orphaned_meta_deleted' => 0,
            'space_saved' => 0,
            'status' => 'completed',
            'cleaned_at' => now(),
        ]);
        $mockCleanup->total_deleted = 5;
        $mockCleanup->formatted_space_saved = '0 B';

        $mockService = Mockery::mock(DatabaseCleanupService::class);
        $mockService->shouldReceive('run')
            ->once()
            ->with(
                Mockery::on(fn ($s) => $s->id === $this->site->id),
                Mockery::on(function ($opts) use (&$capturedOptions) {
                    $capturedOptions = $opts;

                    return true;
                })
            )
            ->andReturn($mockCleanup);

        $this->app->instance(DatabaseCleanupService::class, $mockService);

        Livewire::actingAs($this->admin)
            ->test(SiteDatabaseCleanup::class, ['site' => $this->site])
            ->set('cleanRevisions', false)
            ->set('cleanTransients', true)
            ->call('runCleanup');

        $this->assertFalse($capturedOptions['revisions']);
        $this->assertTrue($capturedOptions['transients']);
    }

    // ─── Authorization ────────────────────────────────────────────────

    #[Test]
    public function viewer_cannot_access_other_users_site_database_cleanup(): void
    {
        $viewer = User::factory()->viewer()->create();
        $otherAdmin = User::factory()->admin()->create();
        $otherSite = Site::factory()->for($otherAdmin)->create();

        Livewire::actingAs($viewer)
            ->test(SiteDatabaseCleanup::class, ['site' => $otherSite])
            ->assertForbidden();
    }
}
