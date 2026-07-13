<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Contracts\WordPressApiServiceInterface;
use App\Enums\SecurityCategory;
use App\Jobs\PullSecurityActivityLogs;
use App\Models\SecuritySetting;
use App\Models\Site;
use App\Services\WordPressApiServiceFactory;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P3-21: activity_log is a Laravel-only security setting — it is never part of
 * the security-settings push payload, so it must NOT be credited on push (which
 * happened before). Its enforcement is confirmed only when the connector's
 * audit-log endpoint actually responds, at which point PullSecurityActivityLogs
 * marks it applied and the hardening score reflects reality.
 */
class ActivityLogEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function jsonResponse(array $body, int $status = 200): Response
    {
        return new Response(new Psr7Response($status, ['Content-Type' => 'application/json'], json_encode($body)));
    }

    private function bindApi(Response $response): void
    {
        $api = $this->createMock(WordPressApiServiceInterface::class);
        $api->method('request')->willReturn($response);
        $this->app->instance(WordPressApiServiceFactory::class, $this->createMockApiFactory($api));
    }

    private function activityLogSetting(Site $site): SecuritySetting
    {
        return SecuritySetting::factory()->create([
            'site_id' => $site->id,
            'category' => SecurityCategory::ActivityLog,
            'setting_key' => 'activity_log_config',
            'is_enabled' => true,
            'applied_at' => null,
        ]);
    }

    public function test_activity_log_is_not_credited_until_a_successful_pull_verifies_it(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);
        $setting = $this->activityLogSetting($site);

        // A failed audit-log fetch must NOT credit the setting.
        $this->bindApi($this->jsonResponse(['error' => 'boom'], 500));
        (new PullSecurityActivityLogs($site))->handle();

        $this->assertNull($setting->fresh()->applied_at, 'activity_log must stay uncredited when the pull fails');

        // A successful audit-log fetch confirms enforcement and credits it.
        $this->bindApi($this->jsonResponse(['logs' => []]));
        (new PullSecurityActivityLogs($site))->handle();

        $this->assertNotNull($setting->fresh()->applied_at, 'activity_log must be applied once the connector confirms audit logging');
        $this->assertGreaterThan(0, $site->fresh()->security_hardening_score);
    }
}
