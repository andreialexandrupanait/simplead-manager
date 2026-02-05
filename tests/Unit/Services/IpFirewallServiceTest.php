<?php

namespace Tests\Unit\Services;

use App\Models\ActivityLog;
use App\Models\BlockedRequest;
use App\Models\IpRule;
use App\Services\IpFirewallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Traits\CreatesSite;
use Tests\Traits\MocksWordPressApi;

class IpFirewallServiceTest extends TestCase
{
    use RefreshDatabase, CreatesSite, MocksWordPressApi;

    // ------------------------------------------------------------------ //
    //  addRule
    // ------------------------------------------------------------------ //

    public function test_add_rule_creates_ip_rule_record(): void
    {
        $site = $this->createSite();
        $this->fakeWordPressApi();

        $rule = IpFirewallService::addRule($site, '192.168.1.100', 'block', 'Suspicious activity');

        $this->assertInstanceOf(IpRule::class, $rule);
        $this->assertDatabaseHas('ip_rules', [
            'id' => $rule->id,
            'site_id' => $site->id,
            'ip_address' => '192.168.1.100',
            'type' => 'block',
            'reason' => 'Suspicious activity',
        ]);
    }

    public function test_add_rule_syncs_to_wordpress_site(): void
    {
        $site = $this->createSite();
        $this->fakeWordPressApi();

        IpFirewallService::addRule($site, '10.0.0.1', 'block');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'ip-rules/sync');
        });
    }

    public function test_add_rule_creates_activity_log(): void
    {
        $site = $this->createSite();
        $this->fakeWordPressApi();

        IpFirewallService::addRule($site, '10.0.0.1', 'block', 'Bot detected');

        $this->assertDatabaseHas('activity_logs', [
            'site_id' => $site->id,
            'type' => 'security',
        ]);
    }

    // ------------------------------------------------------------------ //
    //  removeRule
    // ------------------------------------------------------------------ //

    public function test_remove_rule_deletes_the_rule(): void
    {
        $site = $this->createSite();
        $this->fakeWordPressApi();

        $rule = IpRule::factory()->create([
            'site_id' => $site->id,
            'ip_address' => '10.0.0.1',
            'type' => 'block',
        ]);

        IpFirewallService::removeRule($rule);

        $this->assertDatabaseMissing('ip_rules', ['id' => $rule->id]);
    }

    public function test_remove_rule_syncs_to_site(): void
    {
        $site = $this->createSite();
        $this->fakeWordPressApi();

        $rule = IpRule::factory()->create([
            'site_id' => $site->id,
            'ip_address' => '10.0.0.1',
            'type' => 'block',
        ]);

        IpFirewallService::removeRule($rule);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'ip-rules/sync');
        });
    }

    // ------------------------------------------------------------------ //
    //  syncToSite
    // ------------------------------------------------------------------ //

    public function test_sync_to_site_sends_rules_payload_to_api(): void
    {
        $site = $this->createSite();
        $this->fakeWordPressApi();

        IpRule::factory()->create([
            'site_id' => $site->id,
            'ip_address' => '10.0.0.1',
            'type' => 'block',
            'is_synced' => false,
        ]);

        IpFirewallService::syncToSite($site);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'ip-rules/sync');
        });
    }

    public function test_sync_to_site_marks_rules_as_synced(): void
    {
        $site = $this->createSite();
        $this->fakeWordPressApi();

        $rule = IpRule::factory()->create([
            'site_id' => $site->id,
            'ip_address' => '10.0.0.1',
            'type' => 'block',
            'is_synced' => false,
        ]);

        IpFirewallService::syncToSite($site);

        $rule->refresh();
        $this->assertTrue($rule->is_synced);
    }

    public function test_sync_to_site_handles_api_failure_gracefully(): void
    {
        $site = $this->createSite();

        Http::fake([
            '*/wp-json/simplead/v1/ip-rules/sync' => Http::response('Server Error', 500),
            '*' => Http::response([]),
        ]);

        IpRule::factory()->create([
            'site_id' => $site->id,
            'ip_address' => '10.0.0.1',
            'type' => 'block',
            'is_synced' => false,
        ]);

        // Should not throw an exception
        IpFirewallService::syncToSite($site);

        $this->assertTrue(true); // If we reach here, no exception was thrown
    }

    // ------------------------------------------------------------------ //
    //  fetchBlockedRequests
    // ------------------------------------------------------------------ //

    public function test_fetch_blocked_requests_creates_blocked_request_records(): void
    {
        $site = $this->createSite();

        Http::fake([
            '*/wp-json/simplead/v1/blocked-requests*' => Http::response([
                'requests' => [
                    [
                        'ip_address' => '10.0.0.50',
                        'request_url' => '/wp-login.php',
                        'user_agent' => 'EvilBot/1.0',
                        'blocked_at' => now()->toIso8601String(),
                    ],
                ],
            ]),
            '*' => Http::response([]),
        ]);

        $count = IpFirewallService::fetchBlockedRequests($site);

        $this->assertEquals(1, $count);
        $this->assertDatabaseHas('blocked_requests', [
            'site_id' => $site->id,
            'ip_address' => '10.0.0.50',
            'request_url' => '/wp-login.php',
        ]);
    }

    public function test_fetch_blocked_requests_increments_matching_rule_hit_count(): void
    {
        $site = $this->createSite();

        $rule = IpRule::factory()->create([
            'site_id' => $site->id,
            'ip_address' => '10.0.0.50',
            'type' => 'block',
            'hits_count' => 5,
        ]);

        Http::fake([
            '*/wp-json/simplead/v1/blocked-requests*' => Http::response([
                'requests' => [
                    [
                        'ip_address' => '10.0.0.50',
                        'request_url' => '/wp-login.php',
                        'blocked_at' => now()->toIso8601String(),
                    ],
                ],
            ]),
            '*' => Http::response([]),
        ]);

        IpFirewallService::fetchBlockedRequests($site);

        $rule->refresh();
        $this->assertEquals(6, $rule->hits_count);
    }

    public function test_fetch_blocked_requests_returns_count_of_new_requests(): void
    {
        $site = $this->createSite();

        Http::fake([
            '*/wp-json/simplead/v1/blocked-requests*' => Http::response([
                'requests' => [
                    ['ip_address' => '10.0.0.1', 'blocked_at' => now()->toIso8601String()],
                    ['ip_address' => '10.0.0.2', 'blocked_at' => now()->toIso8601String()],
                    ['ip_address' => '10.0.0.3', 'blocked_at' => now()->toIso8601String()],
                ],
            ]),
            '*' => Http::response([]),
        ]);

        $count = IpFirewallService::fetchBlockedRequests($site);

        $this->assertEquals(3, $count);
    }

    public function test_fetch_blocked_requests_handles_api_failure_returning_zero(): void
    {
        $site = $this->createSite();

        Http::fake([
            '*/wp-json/simplead/v1/blocked-requests*' => Http::response('Server Error', 500),
            '*' => Http::response([]),
        ]);

        $count = IpFirewallService::fetchBlockedRequests($site);

        $this->assertEquals(0, $count);
    }
}
