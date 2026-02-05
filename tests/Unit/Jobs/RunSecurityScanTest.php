<?php

namespace Tests\Unit\Jobs;

use App\Jobs\RunSecurityScan;
use App\Models\SecurityScan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Traits\CreatesSite;
use Tests\Traits\MocksWordPressApi;

class RunSecurityScanTest extends TestCase
{
    use RefreshDatabase, CreatesSite, MocksWordPressApi;

    public function test_job_delegates_to_security_scan_service_and_creates_scan(): void
    {
        $site = $this->createSite(['url' => 'https://example.com']);

        // Fake all HTTP calls the scan service makes
        Http::fake([
            'https://example.com' => Http::response('OK', 200, [
                'X-Frame-Options' => 'SAMEORIGIN',
                'X-Content-Type-Options' => 'nosniff',
                'X-XSS-Protection' => '1; mode=block',
                'Referrer-Policy' => 'strict-origin-when-cross-origin',
                'Permissions-Policy' => 'camera=()',
                'Content-Security-Policy' => "default-src 'self'",
                'Strict-Transport-Security' => 'max-age=31536000',
            ]),
            '*' => Http::response([]),
        ]);

        $job = new RunSecurityScan($site);
        $job->handle();

        // Verify a SecurityScan was created for this site
        $this->assertDatabaseHas('security_scans', [
            'site_id' => $site->id,
        ]);

        $scan = SecurityScan::where('site_id', $site->id)->first();
        $this->assertNotNull($scan);
        $this->assertNotNull($scan->score);
    }

    public function test_job_has_correct_retry_and_timeout_configuration(): void
    {
        $site = $this->createSite();

        $job = new RunSecurityScan($site);

        $this->assertEquals(2, $job->tries);
        $this->assertEquals(300, $job->timeout);
    }
}
