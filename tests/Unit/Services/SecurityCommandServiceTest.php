<?php

namespace Tests\Unit\Services;

use App\Enums\SecurityCommandPriority;
use App\Enums\SecurityCommandStatus;
use App\Models\SecurityCommand;
use App\Models\SecuritySetting;
use App\Models\Site;
use App\Services\SecurityCommandService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SecurityCommandServiceTest extends TestCase
{
    use RefreshDatabase;

    private SecurityCommandService $service;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SecurityCommandService::class);
        $this->site = Site::factory()->create();
    }

    private function createCommand(array $overrides = []): SecurityCommand
    {
        $command = new SecurityCommand;
        $command->forceFill(array_merge([
            'site_id' => $this->site->id,
            'category' => 'hardening',
            'action' => 'disable_theme_editor',
            'priority' => SecurityCommandPriority::Normal,
            'status' => SecurityCommandStatus::Pending,
            'attempts' => 0,
            'max_attempts' => 3,
        ], $overrides));
        $command->save();

        return $command->fresh();
    }

    #[Test]
    public function get_pending_commands_returns_only_pending(): void
    {
        $this->createCommand();
        $this->createCommand([
            'action' => 'hide_wp_version',
            'status' => SecurityCommandStatus::Completed,
        ]);

        $commands = $this->service->getPendingCommands($this->site);

        $this->assertCount(1, $commands);
        $this->assertEquals('disable_theme_editor', $commands->first()->action);
    }

    #[Test]
    public function get_pending_commands_orders_by_priority(): void
    {
        $this->createCommand(['action' => 'normal_action', 'priority' => SecurityCommandPriority::Normal]);
        $this->createCommand(['action' => 'critical_action', 'priority' => SecurityCommandPriority::Critical]);

        $commands = $this->service->getPendingCommands($this->site);

        $this->assertEquals('critical_action', $commands->first()->action);
        $this->assertEquals('normal_action', $commands->last()->action);
    }

    #[Test]
    public function create_command_cancels_existing_pending_for_same_action(): void
    {
        $existing = $this->service->createCommand($this->site, 'hardening', 'disable_theme_editor', ['enabled' => true]);

        $this->service->createCommand($this->site, 'hardening', 'disable_theme_editor', ['enabled' => false]);

        $existing->refresh();
        $this->assertEquals(SecurityCommandStatus::Cancelled, $existing->status);

        // New command should be pending
        $pending = SecurityCommand::where('site_id', $this->site->id)
            ->where('status', SecurityCommandStatus::Pending)
            ->latest('id')
            ->first();
        $this->assertNotNull($pending);
    }

    #[Test]
    public function create_command_does_not_cancel_completed_commands(): void
    {
        $completed = $this->createCommand(['status' => SecurityCommandStatus::Completed]);

        $this->service->createCommand($this->site, 'hardening', 'disable_theme_editor', ['enabled' => true]);

        $completed->refresh();
        $this->assertEquals(SecurityCommandStatus::Completed, $completed->status);
    }

    #[Test]
    public function process_command_result_marks_as_completed_on_success(): void
    {
        $command = $this->createCommand();

        $this->service->processCommandResult($command, [
            'success' => true,
            'data' => ['applied' => true],
        ]);

        $command->refresh();
        $this->assertEquals(SecurityCommandStatus::Completed, $command->status);
        $this->assertNotNull($command->completed_at);
    }

    #[Test]
    public function process_command_result_updates_setting_on_success(): void
    {
        SecuritySetting::create([
            'site_id' => $this->site->id,
            'category' => 'hardening',
            'setting_key' => 'disable_theme_editor',
            'setting_value' => true,
            'is_enabled' => true,
        ]);

        $command = $this->createCommand();

        $this->service->processCommandResult($command, ['success' => true]);

        $setting = SecuritySetting::where('site_id', $this->site->id)
            ->where('setting_key', 'disable_theme_editor')
            ->first();

        $this->assertNotNull($setting->applied_at);
        $this->assertNull($setting->failed_at);
    }

    #[Test]
    public function process_command_result_retries_on_failure_if_under_max_attempts(): void
    {
        $command = $this->createCommand(['attempts' => 0, 'max_attempts' => 3]);

        $this->service->processCommandResult($command, [
            'success' => false,
            'error' => 'File not writable',
        ]);

        $command->refresh();
        $this->assertEquals(SecurityCommandStatus::Pending, $command->status);
    }

    #[Test]
    public function process_command_result_marks_failed_when_max_attempts_reached(): void
    {
        $command = $this->createCommand(['attempts' => 3, 'max_attempts' => 3]);

        $this->service->processCommandResult($command, [
            'success' => false,
            'error' => 'File not writable',
        ]);

        $command->refresh();
        $this->assertEquals(SecurityCommandStatus::Failed, $command->status);
    }

    #[Test]
    public function cleanup_stale_commands_retries_retryable(): void
    {
        $command = $this->createCommand([
            'status' => SecurityCommandStatus::PickedUp,
            'picked_up_at' => now()->subMinutes(31),
            'attempts' => 1,
            'max_attempts' => 3,
        ]);

        $count = $this->service->cleanupStaleCommands();

        $this->assertEquals(1, $count);
        $command->refresh();
        $this->assertEquals(SecurityCommandStatus::Pending, $command->status);
        $this->assertNull($command->picked_up_at);
    }

    #[Test]
    public function cleanup_stale_commands_fails_non_retryable(): void
    {
        $command = $this->createCommand([
            'status' => SecurityCommandStatus::PickedUp,
            'picked_up_at' => now()->subMinutes(31),
            'attempts' => 3,
            'max_attempts' => 3,
        ]);

        $this->service->cleanupStaleCommands();

        $command->refresh();
        $this->assertEquals(SecurityCommandStatus::Failed, $command->status);
    }

    #[Test]
    public function cleanup_stale_commands_ignores_recent_picked_up(): void
    {
        $this->createCommand([
            'status' => SecurityCommandStatus::PickedUp,
            'picked_up_at' => now()->subMinutes(5),
        ]);

        $count = $this->service->cleanupStaleCommands();

        $this->assertEquals(0, $count);
    }
}
