<?php

namespace Tests\Unit\Services;

use App\Models\SecurityIssue;
use App\Models\SecurityScan;
use App\Models\SslCertificate;
use App\Jobs\SendNotificationJob;
use App\Models\NotificationChannel;
use App\Models\VulnerabilityAlert;
use App\Services\SecurityScanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Traits\CreatesSite;
use Tests\Traits\MocksWordPressApi;

class SecurityScanServiceTest extends TestCase
{
    use RefreshDatabase, CreatesSite, MocksWordPressApi;

    // ------------------------------------------------------------------ //
    //  Scan creates a SecurityScan record
    // ------------------------------------------------------------------ //

    public function test_scan_creates_security_scan_record(): void
    {
        $site = $this->createSite(['url' => 'https://example.com']);

        Http::fake([
            'https://example.com' => Http::response('ok', 200, [
                'x-frame-options' => 'SAMEORIGIN',
                'x-content-type-options' => 'nosniff',
                'x-xss-protection' => '1; mode=block',
                'referrer-policy' => 'strict-origin-when-cross-origin',
                'permissions-policy' => 'camera=()',
                'content-security-policy' => "default-src 'self'",
                'strict-transport-security' => 'max-age=31536000',
            ]),
            '*' => Http::response([]),
        ]);

        $scan = SecurityScanService::scan($site);

        $this->assertInstanceOf(SecurityScan::class, $scan);
        $this->assertDatabaseHas('security_scans', [
            'id' => $scan->id,
            'site_id' => $site->id,
        ]);
    }

    // ------------------------------------------------------------------ //
    //  Missing HTTP headers create SecurityIssue records
    // ------------------------------------------------------------------ //

    public function test_missing_headers_create_security_issues(): void
    {
        $site = $this->createSite(['url' => 'https://example.com']);

        Http::fake([
            'https://example.com' => Http::response('ok', 200, [
                // No security headers at all
            ]),
            '*' => Http::response([]),
        ]);

        SecurityScanService::scan($site);

        // At least the 7 header checks should produce issues
        $headerIssues = SecurityIssue::where('site_id', $site->id)
            ->where('category', 'header')
            ->count();

        $this->assertGreaterThanOrEqual(7, $headerIssues);
    }

    // ------------------------------------------------------------------ //
    //  All headers present = no header issues
    // ------------------------------------------------------------------ //

    public function test_all_headers_present_creates_no_header_issues(): void
    {
        $site = $this->createSite(['url' => 'https://example.com']);

        Http::fake([
            'https://example.com' => Http::response('ok', 200, [
                'x-frame-options' => 'SAMEORIGIN',
                'x-content-type-options' => 'nosniff',
                'x-xss-protection' => '1; mode=block',
                'referrer-policy' => 'strict-origin-when-cross-origin',
                'permissions-policy' => 'camera=()',
                'content-security-policy' => "default-src 'self'",
                'strict-transport-security' => 'max-age=31536000',
            ]),
            '*' => Http::response([]),
        ]);

        SecurityScanService::scan($site);

        $headerIssues = SecurityIssue::where('site_id', $site->id)
            ->where('category', 'header')
            ->count();

        $this->assertEquals(0, $headerIssues);
    }

    // ------------------------------------------------------------------ //
    //  SSL checks
    // ------------------------------------------------------------------ //

    public function test_expired_ssl_creates_critical_issue(): void
    {
        $site = $this->createSite(['url' => 'https://example.com']);
        SslCertificate::factory()->create([
            'site_id' => $site->id,
            'status' => 'expired',
        ]);

        Http::fake([
            'https://example.com' => Http::response('ok', 200, [
                'x-frame-options' => 'SAMEORIGIN',
                'x-content-type-options' => 'nosniff',
                'x-xss-protection' => '1; mode=block',
                'referrer-policy' => 'strict-origin-when-cross-origin',
                'permissions-policy' => 'camera=()',
                'content-security-policy' => "default-src 'self'",
                'strict-transport-security' => 'max-age=31536000',
            ]),
            '*' => Http::response([]),
        ]);

        SecurityScanService::scan($site);

        $this->assertDatabaseHas('security_issues', [
            'site_id' => $site->id,
            'type' => 'ssl_expired',
            'severity' => 'critical',
        ]);
    }

    public function test_expiring_soon_ssl_creates_medium_issue(): void
    {
        $site = $this->createSite(['url' => 'https://example.com']);
        SslCertificate::factory()->create([
            'site_id' => $site->id,
            'status' => 'expiring_soon',
            'days_remaining' => 10,
        ]);

        Http::fake([
            'https://example.com' => Http::response('ok', 200, [
                'x-frame-options' => 'SAMEORIGIN',
                'x-content-type-options' => 'nosniff',
                'x-xss-protection' => '1; mode=block',
                'referrer-policy' => 'strict-origin-when-cross-origin',
                'permissions-policy' => 'camera=()',
                'content-security-policy' => "default-src 'self'",
                'strict-transport-security' => 'max-age=31536000',
            ]),
            '*' => Http::response([]),
        ]);

        SecurityScanService::scan($site);

        $this->assertDatabaseHas('security_issues', [
            'site_id' => $site->id,
            'type' => 'ssl_expiring_soon',
            'severity' => 'medium',
        ]);
    }

    // ------------------------------------------------------------------ //
    //  Score calculation
    // ------------------------------------------------------------------ //

    public function test_score_calculation_deducts_correctly(): void
    {
        $site = $this->createSite(['url' => 'https://example.com']);

        // Provide NO security headers so the scan finds all 7 header issues.
        // Header severities: 5 medium (-5 each = -25), 2 low (-2 each = -4)
        // Expected score: 100 - 25 - 4 = 71
        Http::fake([
            'https://example.com' => Http::response('ok', 200, [
                // no security headers
            ]),
            '*' => Http::response([]),
        ]);

        $scan = SecurityScanService::scan($site);

        // 5 medium headers (x-frame, x-content-type, x-xss, referrer-policy, strict-transport-security)
        // 2 low headers (content-security-policy, permissions-policy)
        // Score: 100 - (5 * 5) - (2 * 2) = 71
        $this->assertEquals(71, $scan->score);
    }

    public function test_score_never_goes_below_zero(): void
    {
        $site = $this->createSite(['url' => 'https://example.com']);

        // Create an expired SSL to add a critical issue (-20),
        // plus missing headers (5 medium = -25, 2 low = -4),
        // plus pre-create vuln_ issues that won't be cleared (vuln_ types excluded from fix-marking).
        SslCertificate::factory()->create([
            'site_id' => $site->id,
            'status' => 'expired',
        ]);

        // Create several vulnerability alerts (active) which count in the score.
        // 5 critical vulns * -20 = -100
        for ($i = 0; $i < 5; $i++) {
            \App\Models\VulnerabilityAlert::factory()->create([
                'site_id' => $site->id,
                'severity' => 'critical',
                'status' => 'active',
            ]);
        }

        Http::fake([
            'https://example.com' => Http::response('ok', 200, [
                // no security headers
            ]),
            '*' => Http::response([]),
        ]);

        $scan = SecurityScanService::scan($site);

        $this->assertEquals(0, $scan->score);
    }

    // ------------------------------------------------------------------ //
    //  Previously found issues get marked as fixed
    // ------------------------------------------------------------------ //

    public function test_previously_found_issues_get_marked_as_fixed(): void
    {
        $site = $this->createSite(['url' => 'https://example.com']);

        // Create an old issue that will NOT appear in the current scan
        $oldIssue = SecurityIssue::factory()->create([
            'site_id' => $site->id,
            'type' => 'old_issue_cleared',
            'category' => 'config',
            'severity' => 'medium',
            'is_fixed' => false,
            'is_ignored' => false,
        ]);

        Http::fake([
            'https://example.com' => Http::response('ok', 200, [
                'x-frame-options' => 'SAMEORIGIN',
                'x-content-type-options' => 'nosniff',
                'x-xss-protection' => '1; mode=block',
                'referrer-policy' => 'strict-origin-when-cross-origin',
                'permissions-policy' => 'camera=()',
                'content-security-policy' => "default-src 'self'",
                'strict-transport-security' => 'max-age=31536000',
            ]),
            '*' => Http::response([]),
        ]);

        SecurityScanService::scan($site);

        $oldIssue->refresh();
        $this->assertTrue($oldIssue->is_fixed);
        $this->assertNotNull($oldIssue->fixed_at);
    }

    // ------------------------------------------------------------------ //
    //  Notification when score < 50
    // ------------------------------------------------------------------ //

    public function test_notification_sent_when_score_below_50(): void
    {
        Bus::fake([SendNotificationJob::class]);

        // Create a default notification channel subscribed to all events
        NotificationChannel::factory()->create([
            'is_default' => true,
            'is_active' => true,
            'event_subscriptions' => null, // subscribed to all events
        ]);

        $site = $this->createSite(['url' => 'https://example.com']);

        // Create critical vulnerability alerts to push score below 50.
        // 3 critical vulns * -20 = -60 => score 100 - 60 = 40
        for ($i = 0; $i < 3; $i++) {
            VulnerabilityAlert::factory()->create([
                'site_id' => $site->id,
                'severity' => 'critical',
                'status' => 'active',
            ]);
        }

        Http::fake([
            'https://example.com' => Http::response('ok', 200, [
                'x-frame-options' => 'SAMEORIGIN',
                'x-content-type-options' => 'nosniff',
                'x-xss-protection' => '1; mode=block',
                'referrer-policy' => 'strict-origin-when-cross-origin',
                'permissions-policy' => 'camera=()',
                'content-security-policy' => "default-src 'self'",
                'strict-transport-security' => 'max-age=31536000',
            ]),
            '*' => Http::response([]),
        ]);

        $scan = SecurityScanService::scan($site);

        $this->assertLessThan(50, $scan->score);
        Bus::assertDispatched(SendNotificationJob::class);
    }

    public function test_no_notification_when_score_is_50_or_above(): void
    {
        Bus::fake([SendNotificationJob::class]);

        NotificationChannel::factory()->create([
            'is_default' => true,
            'is_active' => true,
            'event_subscriptions' => null,
        ]);

        $site = $this->createSite(['url' => 'https://example.com']);

        Http::fake([
            'https://example.com' => Http::response('ok', 200, [
                'x-frame-options' => 'SAMEORIGIN',
                'x-content-type-options' => 'nosniff',
                'x-xss-protection' => '1; mode=block',
                'referrer-policy' => 'strict-origin-when-cross-origin',
                'permissions-policy' => 'camera=()',
                'content-security-policy' => "default-src 'self'",
                'strict-transport-security' => 'max-age=31536000',
            ]),
            '*' => Http::response([]),
        ]);

        $scan = SecurityScanService::scan($site);

        $this->assertGreaterThanOrEqual(50, $scan->score);
        Bus::assertNotDispatched(SendNotificationJob::class);
    }

    // ------------------------------------------------------------------ //
    //  resolveIssue & ignoreIssue
    // ------------------------------------------------------------------ //

    public function test_resolve_issue_marks_issue_as_fixed(): void
    {
        $site = $this->createSite();
        $issue = SecurityIssue::factory()->create([
            'site_id' => $site->id,
            'is_fixed' => false,
        ]);

        SecurityScanService::resolveIssue($issue);

        $issue->refresh();
        $this->assertTrue($issue->is_fixed);
        $this->assertNotNull($issue->fixed_at);
    }

    public function test_ignore_issue_marks_issue_as_ignored(): void
    {
        $site = $this->createSite();
        $issue = SecurityIssue::factory()->create([
            'site_id' => $site->id,
            'is_ignored' => false,
        ]);

        SecurityScanService::ignoreIssue($issue);

        $issue->refresh();
        $this->assertTrue($issue->is_ignored);
    }
}
