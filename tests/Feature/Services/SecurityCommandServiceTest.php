<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\SecurityCategory;
use App\Enums\SecurityCommandPriority;
use App\Enums\SecurityCommandStatus;
use App\Models\SecurityCommand;
use App\Models\SecuritySetting;
use App\Models\Site;
use App\Services\SecurityCommandService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityCommandServiceTest extends TestCase
{
    use RefreshDatabase;

    private SecurityCommandService $service;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SecurityCommandService;
        $this->site = Site::factory()->create();
    }

    public function test_get_pending_commands_returns_ordered_by_priority(): void
    {
        SecurityCommand::factory()->create([
            'site_id' => $this->site->id,
            'priority' => SecurityCommandPriority::Normal,
            'action' => 'normal_action',
        ]);
        SecurityCommand::factory()->create([
            'site_id' => $this->site->id,
            'priority' => SecurityCommandPriority::Critical,
            'action' => 'critical_action',
        ]);
        SecurityCommand::factory()->create([
            'site_id' => $this->site->id,
            'priority' => SecurityCommandPriority::High,
            'action' => 'high_action',
        ]);

        $commands = $this->service->getPendingCommands($this->site);

        $this->assertCount(3, $commands);
        $this->assertSame('critical_action', $commands->first()->action);
        $this->assertSame('normal_action', $commands->last()->action);
    }

    public function test_create_command_cancels_existing_pending(): void
    {
        $existing = SecurityCommand::factory()->create([
            'site_id' => $this->site->id,
            'category' => SecurityCategory::Hardening,
            'action' => 'disable_xml_rpc',
        ]);

        $new = $this->service->createCommand(
            $this->site,
            SecurityCategory::Hardening->value,
            'disable_xml_rpc',
        );

        $this->assertDatabaseHas('security_commands', [
            'id' => $existing->id,
            'status' => 'cancelled',
        ]);
        $this->assertDatabaseHas('security_commands', [
            'id' => $new->id,
            'status' => 'pending',
        ]);
    }

    public function test_create_command_does_not_cancel_different_action(): void
    {
        $other = SecurityCommand::factory()->create([
            'site_id' => $this->site->id,
            'category' => SecurityCategory::Hardening,
            'action' => 'enforce_ssl',
        ]);

        $this->service->createCommand(
            $this->site,
            SecurityCategory::Hardening->value,
            'disable_xml_rpc',
        );

        $other->refresh();
        $this->assertSame(SecurityCommandStatus::Pending, $other->status);
    }

    public function test_process_success_result_marks_completed_and_updates_setting(): void
    {
        $command = SecurityCommand::factory()->pickedUp()->create([
            'site_id' => $this->site->id,
            'category' => SecurityCategory::Hardening,
            'action' => 'disable_xml_rpc',
        ]);

        SecuritySetting::factory()->enabled()->create([
            'site_id' => $this->site->id,
            'category' => SecurityCategory::Hardening,
            'setting_key' => 'disable_xml_rpc',
        ]);

        $this->service->processCommandResult($command, ['success' => true]);

        $command->refresh();
        $this->assertSame(SecurityCommandStatus::Completed, $command->status);
        $this->assertNotNull($command->completed_at);

        $setting = SecuritySetting::where('site_id', $this->site->id)
            ->where('setting_key', 'disable_xml_rpc')
            ->first();
        $this->assertNotNull($setting->applied_at);
        $this->assertNull($setting->failed_at);
    }

    public function test_process_failure_result_retries_if_under_max_attempts(): void
    {
        $command = SecurityCommand::factory()->pickedUp()->create([
            'site_id' => $this->site->id,
            'category' => SecurityCategory::Hardening,
            'action' => 'disable_xml_rpc',
            'attempts' => 1,
            'max_attempts' => 3,
        ]);

        $this->service->processCommandResult($command, ['success' => false, 'error' => 'Timeout']);

        $command->refresh();
        // Should be reset to pending for retry
        $this->assertSame(SecurityCommandStatus::Pending, $command->status);
    }

    public function test_process_failure_marks_failed_at_max_attempts(): void
    {
        $command = SecurityCommand::factory()->pickedUp()->create([
            'site_id' => $this->site->id,
            'category' => SecurityCategory::Hardening,
            'action' => 'disable_xml_rpc',
            'attempts' => 3,
            'max_attempts' => 3,
        ]);

        SecuritySetting::factory()->enabled()->create([
            'site_id' => $this->site->id,
            'category' => SecurityCategory::Hardening,
            'setting_key' => 'disable_xml_rpc',
        ]);

        $this->service->processCommandResult($command, ['success' => false, 'error' => 'Connection failed']);

        $command->refresh();
        $this->assertSame(SecurityCommandStatus::Failed, $command->status);

        $setting = SecuritySetting::where('setting_key', 'disable_xml_rpc')->first();
        $this->assertNotNull($setting->failed_at);
    }

    public function test_cleanup_stale_commands_retries_eligible(): void
    {
        $stale = SecurityCommand::factory()->stale()->create([
            'site_id' => $this->site->id,
            'attempts' => 1,
            'max_attempts' => 3,
        ]);

        $count = $this->service->cleanupStaleCommands();

        $stale->refresh();
        $this->assertSame(1, $count);
        $this->assertSame(SecurityCommandStatus::Pending, $stale->status);
        $this->assertNull($stale->picked_up_at);
    }

    public function test_cleanup_stale_commands_fails_exhausted(): void
    {
        $stale = SecurityCommand::factory()->stale()->create([
            'site_id' => $this->site->id,
            'attempts' => 3,
            'max_attempts' => 3,
        ]);

        $count = $this->service->cleanupStaleCommands();

        $stale->refresh();
        $this->assertSame(1, $count);
        $this->assertSame(SecurityCommandStatus::Failed, $stale->status);
        $this->assertNotNull($stale->completed_at);
    }

    public function test_get_pending_excludes_non_pending(): void
    {
        SecurityCommand::factory()->completed()->create(['site_id' => $this->site->id]);
        SecurityCommand::factory()->failed()->create(['site_id' => $this->site->id]);

        $commands = $this->service->getPendingCommands($this->site);

        $this->assertEmpty($commands);
    }
}
