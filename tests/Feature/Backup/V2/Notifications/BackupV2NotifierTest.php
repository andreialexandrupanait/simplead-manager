<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Notifications;

use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Notifications\BackupV2Notifier;
use App\Backup\V2\Quota\QuotaService;
use App\Mail\Backup\V2\BackupV2SuccessMail;
use App\Mail\Backup\V2\StorageQuotaReachedMail;
use App\Models\Site;
use App\Models\StorageDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackupV2NotifierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config([
            'backup_v2.notifications.recipients' => ['ops@example.com'],
        ]);
    }

    private function completedSession(): BackupSession
    {
        $site = Site::factory()->create();

        return BackupSession::create([
            'site_id' => $site->id,
            'type' => 'full',
            'resource_profile' => 'low_impact',
            'state' => BackupSessionState::Completed,
            'confirmed_objects' => [],
            'confirmed_parts' => [],
            'idempotency_key' => 'notif-'.Str::random(20),
            'format_version' => 'simplead-backup/1',
            'completed_at' => now(),
        ]);
    }

    public function test_backup_success_sends_mail_when_enabled(): void
    {
        config(['backup_v2.notifications.enabled' => true, 'backup_v2.notifications.notify_on_success' => true]);

        $sent = (new BackupV2Notifier)->backupSucceeded($this->completedSession());

        $this->assertTrue($sent);
        Mail::assertSent(BackupV2SuccessMail::class, fn ($m) => $m->hasTo('ops@example.com'));
    }

    public function test_backup_success_not_sent_when_disabled(): void
    {
        config(['backup_v2.notifications.enabled' => false]);

        $sent = (new BackupV2Notifier)->backupSucceeded($this->completedSession());

        $this->assertFalse($sent);
        Mail::assertNothingSent();
    }

    public function test_quota_reached_sends_mail_when_enabled(): void
    {
        config(['backup_v2.notifications.enabled' => true]);
        $d = StorageDestination::factory()->create(['used_bytes' => 10_000_000, 'quota_bytes' => 10_000_000]);

        $sent = (new BackupV2Notifier)->storageQuotaReached($d);

        $this->assertTrue($sent);
        Mail::assertSent(StorageQuotaReachedMail::class);
    }

    public function test_quota_service_alerts_when_over_threshold(): void
    {
        config([
            'backup_v2.notifications.enabled' => true,
            'backup_v2.quota.warn_threshold_percent' => 90,
        ]);
        $d = StorageDestination::factory()->create(['used_bytes' => 9_500_000, 'quota_bytes' => 10_000_000]);

        $fired = app(QuotaService::class)->checkAndAlert($d);

        $this->assertTrue($fired);
        Mail::assertSent(StorageQuotaReachedMail::class);
    }

    public function test_recipients_fall_back_to_from_address(): void
    {
        config([
            'backup_v2.notifications.enabled' => true,
            'backup_v2.notifications.recipients' => [],
            'mail.from.address' => 'fallback@example.com',
        ]);

        $sent = (new BackupV2Notifier)->backupSucceeded($this->completedSession());

        $this->assertTrue($sent);
        Mail::assertSent(BackupV2SuccessMail::class, fn ($m) => $m->hasTo('fallback@example.com'));
    }
}
