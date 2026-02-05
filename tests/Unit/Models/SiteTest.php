<?php

namespace Tests\Unit\Models;

use App\Models\Client;
use App\Models\DomainMonitor;
use App\Models\LinkMonitor;
use App\Models\PerformanceMonitor;
use App\Models\Site;
use App\Models\SslCertificate;
use App\Models\UptimeMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;
use Tests\Traits\CreatesSite;

class SiteTest extends TestCase
{
    use RefreshDatabase, CreatesSite;

    public function test_domain_attribute_extracts_host_from_url(): void
    {
        $site = $this->createSite(['url' => 'https://www.example.com/page']);

        $this->assertEquals('www.example.com', $site->domain);
    }

    public function test_overall_status_returns_critical_when_is_up_is_false(): void
    {
        $site = $this->createSite([
            'is_up' => false,
            'health_score' => 95,
        ]);

        $this->assertEquals('critical', $site->overall_status);
    }

    public function test_overall_status_returns_unknown_when_health_score_is_null(): void
    {
        $site = $this->createSite([
            'is_up' => true,
            'health_score' => null,
        ]);

        $this->assertEquals('unknown', $site->overall_status);
    }

    public function test_overall_status_returns_healthy_when_score_gte_90(): void
    {
        $site = $this->createSite([
            'is_up' => true,
            'health_score' => 95,
        ]);

        $this->assertEquals('healthy', $site->overall_status);
    }

    public function test_overall_status_returns_warning_when_score_gte_70(): void
    {
        $site = $this->createSite([
            'is_up' => true,
            'health_score' => 75,
        ]);

        $this->assertEquals('warning', $site->overall_status);
    }

    public function test_overall_status_returns_critical_when_score_lt_70(): void
    {
        $site = $this->createSite([
            'is_up' => true,
            'health_score' => 55,
        ]);

        $this->assertEquals('critical', $site->overall_status);
    }

    public function test_extract_root_domain_extracts_from_full_url(): void
    {
        $result = Site::extractRootDomain('https://example.com/some/path');

        $this->assertEquals('example.com', $result);
    }

    public function test_extract_root_domain_handles_www_prefix(): void
    {
        $result = Site::extractRootDomain('https://www.example.com');

        $this->assertEquals('example.com', $result);
    }

    public function test_extract_root_domain_handles_subdomains(): void
    {
        $result = Site::extractRootDomain('https://blog.shop.example.com');

        $this->assertEquals('example.com', $result);
    }

    public function test_site_uses_soft_deletes(): void
    {
        $site = $this->createSite();

        $site->delete();

        $this->assertSoftDeleted('sites', ['id' => $site->id]);
        $this->assertNotNull($site->fresh()->deleted_at);
    }

    public function test_site_has_encrypted_api_key_cast(): void
    {
        $site = $this->createSite(['api_key' => 'test-secret-key-123']);

        // Reload from database
        $freshSite = Site::withoutEvents(fn () => Site::find($site->id));

        // The value should be decrypted transparently by Laravel
        $this->assertEquals('test-secret-key-123', $freshSite->api_key);

        // Verify the raw value in the database is not the plaintext
        $rawValue = \DB::table('sites')->where('id', $site->id)->value('api_key');
        $this->assertNotEquals('test-secret-key-123', $rawValue);
    }

    public function test_site_has_encrypted_api_secret_cast(): void
    {
        $site = $this->createSite(['api_secret' => 'my-secret-value-456']);

        $freshSite = Site::withoutEvents(fn () => Site::find($site->id));

        $this->assertEquals('my-secret-value-456', $freshSite->api_secret);

        $rawValue = \DB::table('sites')->where('id', $site->id)->value('api_secret');
        $this->assertNotEquals('my-secret-value-456', $rawValue);
    }

    public function test_booted_creates_related_monitors_on_site_creation(): void
    {
        Bus::fake();

        $client = Client::factory()->create();

        // Create site WITH events (not using createSite() which suppresses events)
        $site = Site::factory()->create([
            'client_id' => $client->id,
            'url' => 'https://www.testsite.com',
        ]);

        // Check that related monitors were created by the booted() callback
        $this->assertNotNull($site->uptimeMonitor, 'Uptime monitor should be created');
        $this->assertNotNull($site->sslCertificate, 'SSL certificate monitor should be created');
        $this->assertNotNull($site->domainMonitor, 'Domain monitor should be created');
        $this->assertNotNull($site->performanceMonitor, 'Performance monitor should be created');
        $this->assertNotNull($site->linkMonitor, 'Link monitor should be created');
    }
}
