<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\CheckLicenseExpiry;
use App\Models\Site;
use App\Models\SitePlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Faza 5.4 — premium license-expiry alerting.
 *
 * Asserts the alert side effects via the in-app notification the slim path always
 * writes for the site owner (channel-independent), plus the per-plugin dedup marker.
 */
class CheckLicenseExpiryTest extends TestCase
{
    use RefreshDatabase;

    private function licensedPlugin(Site $site, \DateTimeInterface $expiresAt): SitePlugin
    {
        return SitePlugin::factory()->create([
            'site_id' => $site->id,
            'name' => 'Advanced Custom Fields Pro',
            'slug' => 'advanced-custom-fields-pro',
            'file' => 'advanced-custom-fields-pro/acf.php',
            'is_on_wp_org' => false,
            'license_status' => 'active',
            'license_expires_at' => $expiresAt,
            'license_alert_sent_at' => null,
        ]);
    }

    public function test_license_expiring_in_29_days_emits_one_warning_and_stamps_marker(): void
    {
        Queue::fake();

        $site = Site::factory()->create(['is_connected' => true]);
        $plugin = $this->licensedPlugin($site, now()->addDays(29));

        (new CheckLicenseExpiry)->handle();

        $this->assertDatabaseCount('in_app_notifications', 1);
        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $site->user_id,
            'type' => 'warning',
        ]);
        $this->assertNotNull($plugin->fresh()->license_alert_sent_at, 'The dedup marker must be stamped after alerting.');
    }

    public function test_second_daily_run_does_not_re_notify_within_dedup_window(): void
    {
        Queue::fake();

        $site = Site::factory()->create(['is_connected' => true]);
        $plugin = $this->licensedPlugin($site, now()->addDays(29));

        (new CheckLicenseExpiry)->handle();
        $this->assertDatabaseCount('in_app_notifications', 1);
        $firstStamp = $plugin->fresh()->license_alert_sent_at;

        // Advance a day and sweep again — the expiry is still unresolved but the
        // weekly per-plugin dedup must suppress a repeat alert.
        $this->travel(1)->days();
        (new CheckLicenseExpiry)->handle();

        // A second sweep within the dedup window must not re-notify.
        $this->assertDatabaseCount('in_app_notifications', 1);
        $this->assertEquals(
            $firstStamp->toDateTimeString(),
            $plugin->fresh()->license_alert_sent_at->toDateTimeString(),
            'The dedup marker must not advance when the alert is suppressed.'
        );
    }

    public function test_expired_license_emits_critical_alert(): void
    {
        Queue::fake();

        $site = Site::factory()->create(['is_connected' => true]);
        $plugin = $this->licensedPlugin($site, now()->subDays(3));

        (new CheckLicenseExpiry)->handle();

        $this->assertDatabaseCount('in_app_notifications', 1);
        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $site->user_id,
            'type' => 'critical',
        ]);
        $this->assertNotNull($plugin->fresh()->license_alert_sent_at);
    }

    public function test_renewed_license_resets_marker_and_does_not_notify(): void
    {
        Queue::fake();

        $site = Site::factory()->create(['is_connected' => true]);
        // Renewed far into the future, but still carrying a stale alert marker.
        $plugin = SitePlugin::factory()->create([
            'site_id' => $site->id,
            'name' => 'WP Rocket',
            'slug' => 'wp-rocket',
            'file' => 'wp-rocket/wp-rocket.php',
            'is_on_wp_org' => false,
            'license_status' => 'active',
            'license_expires_at' => now()->addDays(300),
            'license_alert_sent_at' => now()->subDays(2),
        ]);

        (new CheckLicenseExpiry)->handle();

        // A healthy license must not alert.
        $this->assertDatabaseCount('in_app_notifications', 0);
        $this->assertNull(
            $plugin->fresh()->license_alert_sent_at,
            'A renewed license must clear the dedup marker so a future expiry alerts afresh.'
        );
    }
}
