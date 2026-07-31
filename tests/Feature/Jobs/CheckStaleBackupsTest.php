<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\CheckStaleBackups;
use App\Jobs\SendNotificationJob;
use App\Models\BackupConfig;
use App\Models\NotificationChannel;
use App\Models\Site;
use App\Models\StorageDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Alerting on backup *failures* has always worked, because a failure is a row
 * transitioning to `failed` and an observer watches for exactly that. Alerting
 * on backup *absence* did not exist at all: a site whose backups quietly
 * stopped being dispatched produces no transition, so nothing fires, for as
 * long as it takes someone to look at a screen. simplead.ro went 51 days.
 */
class CheckStaleBackupsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        // A default channel subscribed to everything, so the service reaches the
        // point of recording an outgoing alert.
        NotificationChannel::factory()->create([
            'type' => 'slack',
            'config' => ['webhook_url' => 'https://hooks.slack.com/services/T00/B00/XXX'],
            'is_default' => true,
            'is_active' => true,
            'event_subscriptions' => null,
        ]);
    }

    /**
     * The alerts this run produced, read off the jobs the notification service
     * actually queued rather than a stand-in for it — so the assertions hold
     * for the real delivery path, not for a mock's idea of it.
     *
     * @return list<SendNotificationJob>
     */
    private function staleAlerts(): array
    {
        $found = [];
        Queue::pushed(SendNotificationJob::class, function (SendNotificationJob $job) use (&$found) {
            if ($job->event === 'backup_stale') {
                $found[] = $job;
            }

            return false;
        });

        return $found;
    }

    private function siteWithBackup(?string $lastBackupAgo, bool $enabled = true): Site
    {
        $site = Site::factory()->create([
            'last_backup_at' => $lastBackupAgo === null ? null : now()->sub($lastBackupAgo),
        ]);

        BackupConfig::factory()->create([
            'site_id' => $site->id,
            'storage_destination_id' => StorageDestination::factory()->local()->create()->id,
            'is_enabled' => $enabled,
        ]);

        return $site;
    }

    public function test_a_fresh_backup_raises_nothing(): void
    {
        $this->siteWithBackup('2 hours');

        (new CheckStaleBackups)->handle();

        $this->assertCount(0, $this->staleAlerts());
    }

    public function test_a_late_backup_is_a_warning(): void
    {
        $this->siteWithBackup('40 hours');

        (new CheckStaleBackups)->handle();

        $alerts = $this->staleAlerts();
        $this->assertCount(1, $alerts);
        $this->assertSame('warning', $alerts[0]->severity);
    }

    public function test_three_missed_nights_is_critical(): void
    {
        $this->siteWithBackup('80 hours');

        (new CheckStaleBackups)->handle();

        $this->assertSame('critical', $this->staleAlerts()[0]->severity);
    }

    /**
     * A site that has never produced a backup is not late — it is unprotected,
     * and has been since the day someone enabled backups for it.
     */
    public function test_a_site_that_never_backed_up_is_critical_and_says_so(): void
    {
        $this->siteWithBackup(null);

        (new CheckStaleBackups)->handle();

        $alert = $this->staleAlerts()[0];
        $this->assertSame('critical', $alert->severity);
        $this->assertStringContainsString('never', $alert->message);
    }

    public function test_a_site_with_backups_switched_off_is_left_alone(): void
    {
        $this->siteWithBackup(null, enabled: false);

        (new CheckStaleBackups)->handle();

        $this->assertCount(0, $this->staleAlerts());
    }

    /**
     * The point of the persisted marker: a daily sweep must not report the same
     * site every single morning until someone fixes it.
     */
    public function test_the_same_site_is_not_reported_again_the_next_day(): void
    {
        $this->siteWithBackup('40 hours');

        (new CheckStaleBackups)->handle();
        (new CheckStaleBackups)->handle();

        $this->assertCount(1, $this->staleAlerts());
    }

    public function test_it_speaks_up_again_once_the_re_alert_window_passes(): void
    {
        $site = $this->siteWithBackup('40 hours');

        (new CheckStaleBackups)->handle();

        $site->backupConfig->updateQuietly([
            'stale_alert_sent_at' => now()->subDays((int) config('backups.stale_realert_after_days', 3) + 1),
        ]);

        // Past NotificationService's own 5-minute burst dedup. Two independent
        // guards sit on this path: that one stops a storm of identical events
        // seconds apart, the marker stops a daily sweep nagging for weeks. In
        // production they never interact; in a test running both sweeps inside
        // the same second, they do.
        $this->travel(6)->minutes();
        (new CheckStaleBackups)->handle();

        $this->assertCount(2, $this->staleAlerts());
    }

    /**
     * A recovered site must have its marker cleared, or its next lapse would
     * pass in silence behind a marker nobody reset.
     */
    public function test_a_recovered_site_can_alert_again_on_its_next_lapse(): void
    {
        $site = $this->siteWithBackup('40 hours');

        (new CheckStaleBackups)->handle();
        $this->assertNotNull($site->backupConfig->fresh()->stale_alert_sent_at);

        // Backup succeeds.
        $site->update(['last_backup_at' => now()]);
        (new CheckStaleBackups)->handle();
        $this->assertNull($site->backupConfig->fresh()->stale_alert_sent_at);

        // …and lapses again, immediately, without waiting out the re-alert window.
        $site->update(['last_backup_at' => now()->subHours(40)]);
        $this->travel(6)->minutes();   // past the burst dedup, as above
        (new CheckStaleBackups)->handle();

        $this->assertCount(2, $this->staleAlerts());
    }

    public function test_the_threshold_comes_from_config_not_a_literal(): void
    {
        config(['backups.stale_after_hours' => 200]);
        $this->siteWithBackup('100 hours');

        (new CheckStaleBackups)->handle();

        $this->assertCount(0, $this->staleAlerts(), 'A site inside a widened window must not be reported.');
    }
}
