<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\SecurityCategory;
use App\Jobs\PushSecuritySettings;
use App\Jobs\PushSiteTweaksSettings;
use App\Models\SecuritySetting;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P1-62: the security/tweaks push jobs previously had no failed() handler, so
 * once the queue exhausted retries (or the worker was hard-killed mid-push),
 * enabled-but-unapplied settings sat as "Pending" (applied_at & failed_at both
 * null) forever and the hardening score stayed stale. failed() must now mark
 * the stuck settings as failed — the visible error state the dashboard reads —
 * and recompute the security score.
 */
class PushSettingsFailedHandlerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Site::created() dispatches outbound jobs (favicon, plan apply).
        Queue::fake();
    }

    private function pendingSetting(Site $site, string $category, string $key): SecuritySetting
    {
        return SecuritySetting::create([
            'site_id' => $site->id,
            'category' => $category,
            'setting_key' => $key,
            'setting_value' => null,
            'is_enabled' => true,
            'applied_at' => null,   // "Pending"
            'failed_at' => null,
            'failure_reason' => null,
        ]);
    }

    public function test_push_security_failed_marks_pending_settings_failed(): void
    {
        $site = Site::factory()->create(['security_hardening_score' => 90]);
        $pending = $this->pendingSetting($site, SecurityCategory::Hardening->value, 'disable_file_editor');

        (new PushSecuritySettings($site))->failed(new \RuntimeException('connector down'));

        $pending->refresh();
        $this->assertNotNull($pending->failed_at, 'stuck-pending setting should be marked failed');
        $this->assertStringContainsString('connector down', (string) $pending->failure_reason);
    }

    public function test_push_security_failed_recomputes_score_off_stale_optimistic_value(): void
    {
        $site = Site::factory()->create(['security_hardening_score' => 90]);
        $this->pendingSetting($site, SecurityCategory::Hardening->value, 'disable_file_editor');

        (new PushSecuritySettings($site))->failed(new \RuntimeException('boom'));

        // Score is recomputed from actually-applied settings (none applied here),
        // so it must no longer sit at the stale optimistic 90.
        $this->assertNotSame(90, $site->fresh()->security_hardening_score);
    }

    public function test_push_security_failed_leaves_already_applied_settings_untouched(): void
    {
        $site = Site::factory()->create();
        $applied = SecuritySetting::create([
            'site_id' => $site->id,
            'category' => SecurityCategory::Hardening->value,
            'setting_key' => 'hide_wp_version',
            'is_enabled' => true,
            'applied_at' => now(),
            'failed_at' => null,
        ]);

        (new PushSecuritySettings($site))->failed(new \RuntimeException('boom'));

        $applied->refresh();
        $this->assertNull($applied->failed_at, 'already-applied setting must not be clobbered');
        $this->assertNotNull($applied->applied_at);
    }

    public function test_push_tweaks_failed_marks_pending_settings_failed(): void
    {
        $site = Site::factory()->create();
        $pending = $this->pendingSetting($site, 'performance', 'lazy_load_images');

        (new PushSiteTweaksSettings($site))->failed(new \RuntimeException('timeout'));

        $pending->refresh();
        $this->assertNotNull($pending->failed_at);
        $this->assertStringContainsString('timeout', (string) $pending->failure_reason);
    }

    public function test_push_tweaks_failed_tolerates_null_exception(): void
    {
        $site = Site::factory()->create();
        $pending = $this->pendingSetting($site, 'admin_ux', 'hide_admin_bar');

        (new PushSiteTweaksSettings($site))->failed(null);

        $pending->refresh();
        $this->assertNotNull($pending->failed_at);
        $this->assertStringContainsString('unknown error', (string) $pending->failure_reason);
    }
}
