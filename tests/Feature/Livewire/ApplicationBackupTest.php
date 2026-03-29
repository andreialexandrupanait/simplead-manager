<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Jobs\CreateAppBackup;
use App\Livewire\Settings\ApplicationBackup;
use App\Models\AppBackup;
use App\Models\AppBackupConfig;
use App\Models\StorageDestination;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApplicationBackupTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        // Ensure the singleton config row exists
        AppBackupConfig::instance();
    }

    // ─── Rendering ────────────────────────────────────────────────────

    #[Test]
    public function admin_can_view_application_backup_page(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ApplicationBackup::class)
            ->assertOk();
    }

    #[Test]
    public function backup_list_shows_existing_backups(): void
    {
        AppBackup::create([
            'type' => 'full',
            'trigger' => 'manual',
            'status' => 'completed',
            'progress' => 100,
            'sites_count' => 1,
            'users_count' => 1,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ApplicationBackup::class)
            ->assertOk();
    }

    // ─── createBackup() ───────────────────────────────────────────────

    #[Test]
    public function user_can_trigger_application_backup(): void
    {
        Queue::fake();

        Livewire::actingAs($this->admin)
            ->test(ApplicationBackup::class)
            ->call('createBackup');

        Queue::assertPushed(CreateAppBackup::class, function (CreateAppBackup $job) {
            return $job->trigger === 'manual';
        });
    }

    #[Test]
    public function create_backup_with_full_type_dispatches_correct_job(): void
    {
        Queue::fake();

        Livewire::actingAs($this->admin)
            ->test(ApplicationBackup::class)
            ->set('createType', 'full')
            ->call('createBackup');

        Queue::assertPushed(CreateAppBackup::class, function (CreateAppBackup $job) {
            return $job->type === 'full' && $job->trigger === 'manual';
        });
    }

    #[Test]
    public function create_backup_with_database_type_dispatches_correct_job(): void
    {
        Queue::fake();

        Livewire::actingAs($this->admin)
            ->test(ApplicationBackup::class)
            ->set('createType', 'database')
            ->call('createBackup');

        Queue::assertPushed(CreateAppBackup::class, function (CreateAppBackup $job) {
            return $job->type === 'database';
        });
    }

    #[Test]
    public function create_backup_sets_awaiting_flag(): void
    {
        Queue::fake();

        $component = Livewire::actingAs($this->admin)
            ->test(ApplicationBackup::class)
            ->call('createBackup');

        $this->assertTrue($component->get('awaitingBackup'));
    }

    // ─── saveConfig() ─────────────────────────────────────────────────

    #[Test]
    public function user_can_save_backup_configuration(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ApplicationBackup::class)
            ->set('isEnabled', true)
            ->set('frequency', 'daily')
            ->set('time', '03:00')
            ->set('timezone', 'UTC')
            ->set('type', 'full')
            ->set('retentionType', 'count')
            ->set('retentionValue', 7)
            ->call('saveConfig')
            ->assertDispatched('notify');

        $config = AppBackupConfig::instance()->fresh();
        $this->assertTrue($config->is_enabled);
        $this->assertEquals('daily', $config->frequency);
        $this->assertEquals('03:00', $config->time);
    }

    #[Test]
    public function save_config_fails_validation_with_invalid_frequency(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ApplicationBackup::class)
            ->set('frequency', 'hourly')
            ->set('time', '03:00')
            ->set('timezone', 'UTC')
            ->set('type', 'full')
            ->set('retentionType', 'count')
            ->set('retentionValue', 7)
            ->call('saveConfig')
            ->assertHasErrors(['frequency']);
    }

    #[Test]
    public function save_config_fails_validation_with_invalid_time_format(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ApplicationBackup::class)
            ->set('frequency', 'daily')
            ->set('time', 'not-a-time')
            ->set('timezone', 'UTC')
            ->set('type', 'full')
            ->set('retentionType', 'count')
            ->set('retentionValue', 7)
            ->call('saveConfig')
            ->assertHasErrors(['time']);
    }

    // ─── toggleLock() ─────────────────────────────────────────────────

    #[Test]
    public function user_can_toggle_backup_lock(): void
    {
        $backup = AppBackup::create([
            'type' => 'full',
            'trigger' => 'manual',
            'status' => 'completed',
            'progress' => 100,
            'is_locked' => false,
            'sites_count' => 1,
            'users_count' => 1,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ApplicationBackup::class)
            ->call('toggleLock', $backup->id);

        $this->assertDatabaseHas('app_backups', [
            'id' => $backup->id,
            'is_locked' => true,
        ]);
    }

    // ─── deleteBackup() ───────────────────────────────────────────────

    #[Test]
    public function user_cannot_delete_locked_backup(): void
    {
        $backup = AppBackup::create([
            'type' => 'full',
            'trigger' => 'manual',
            'status' => 'completed',
            'progress' => 100,
            'is_locked' => true,
            'lock_reason' => 'manual',
            'sites_count' => 1,
            'users_count' => 1,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ApplicationBackup::class)
            ->call('deleteBackup', $backup->id);

        $this->assertDatabaseHas('app_backups', ['id' => $backup->id]);
    }
}
