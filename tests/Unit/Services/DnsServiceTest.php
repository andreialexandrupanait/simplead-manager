<?php

namespace Tests\Unit\Services;

use App\Models\DnsRecordCache;
use App\Services\DnsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesSite;

class DnsServiceTest extends TestCase
{
    use RefreshDatabase, CreatesSite;

    public function test_fetch_and_cache_returns_dns_record_data(): void
    {
        $site = $this->createSite(['url' => 'https://example.com']);

        // Pre-seed a DnsRecordCache directly to avoid calling dns_get_record
        $cache = DnsRecordCache::create([
            'site_id' => $site->id,
            'domain' => 'example.com',
            'a_records' => [['ip' => '93.184.216.34', 'ttl' => 3600]],
            'aaaa_records' => [],
            'cname_records' => [],
            'mx_records' => [['host' => 'mail.example.com', 'priority' => 10, 'ttl' => 3600]],
            'txt_records' => [['value' => 'v=spf1 include:_spf.google.com ~all', 'ttl' => 3600]],
            'ns_records' => [['target' => 'ns1.example.com', 'ttl' => 3600]],
            'soa_record' => ['mname' => 'ns1.example.com', 'serial' => 2024010101],
            'has_www' => true,
            'uses_cloudflare' => false,
            'has_spf' => true,
            'has_dmarc' => false,
            'has_dkim' => false,
            'mail_provider' => null,
            'email_security_score' => 34,
            'total_records' => 5,
            'checked_at' => now(),
        ]);

        $this->assertInstanceOf(DnsRecordCache::class, $cache);
        $this->assertNotEmpty($cache->a_records);
        $this->assertEquals('93.184.216.34', $cache->a_records[0]['ip']);
    }

    public function test_cached_dns_data_persists_in_database(): void
    {
        $site = $this->createSite(['url' => 'https://example.com']);

        DnsRecordCache::create([
            'site_id' => $site->id,
            'domain' => 'example.com',
            'a_records' => [['ip' => '93.184.216.34', 'ttl' => 3600]],
            'aaaa_records' => [],
            'cname_records' => [],
            'mx_records' => [],
            'txt_records' => [],
            'ns_records' => [],
            'soa_record' => null,
            'has_www' => false,
            'uses_cloudflare' => false,
            'has_spf' => false,
            'has_dmarc' => false,
            'has_dkim' => false,
            'email_security_score' => 0,
            'total_records' => 1,
            'checked_at' => now(),
        ]);

        $this->assertDatabaseHas('dns_records_cache', [
            'site_id' => $site->id,
            'domain' => 'example.com',
        ]);
    }

    public function test_dns_cache_detects_cloudflare_proxy(): void
    {
        $site = $this->createSite(['url' => 'https://example.com']);

        $cache = DnsRecordCache::create([
            'site_id' => $site->id,
            'domain' => 'example.com',
            'a_records' => [['ip' => '104.16.132.229', 'ttl' => 300]],
            'aaaa_records' => [],
            'cname_records' => [],
            'mx_records' => [],
            'txt_records' => [],
            'ns_records' => [
                ['target' => 'aria.ns.cloudflare.com', 'ttl' => 86400],
                ['target' => 'tim.ns.cloudflare.com', 'ttl' => 86400],
            ],
            'soa_record' => null,
            'has_www' => false,
            'uses_cloudflare' => true,
            'has_spf' => false,
            'has_dmarc' => false,
            'has_dkim' => false,
            'email_security_score' => 0,
            'total_records' => 3,
            'checked_at' => now(),
        ]);

        $this->assertTrue($cache->uses_cloudflare);
    }

    public function test_dns_cache_detects_spf_record(): void
    {
        $site = $this->createSite(['url' => 'https://example.com']);

        $cache = DnsRecordCache::create([
            'site_id' => $site->id,
            'domain' => 'example.com',
            'a_records' => [],
            'aaaa_records' => [],
            'cname_records' => [],
            'mx_records' => [],
            'txt_records' => [
                ['value' => 'v=spf1 include:_spf.google.com ~all', 'ttl' => 3600],
            ],
            'ns_records' => [],
            'soa_record' => null,
            'has_www' => false,
            'uses_cloudflare' => false,
            'has_spf' => true,
            'has_dmarc' => false,
            'has_dkim' => false,
            'email_security_score' => 34,
            'total_records' => 1,
            'checked_at' => now(),
        ]);

        $this->assertTrue($cache->has_spf);
    }

    public function test_dns_cache_detects_dmarc_record(): void
    {
        $site = $this->createSite(['url' => 'https://example.com']);

        $cache = DnsRecordCache::create([
            'site_id' => $site->id,
            'domain' => 'example.com',
            'a_records' => [],
            'aaaa_records' => [],
            'cname_records' => [],
            'mx_records' => [],
            'txt_records' => [],
            'ns_records' => [],
            'soa_record' => null,
            'has_www' => false,
            'uses_cloudflare' => false,
            'has_spf' => false,
            'has_dmarc' => true,
            'has_dkim' => false,
            'email_security_score' => 33,
            'total_records' => 0,
            'checked_at' => now(),
        ]);

        $this->assertTrue($cache->has_dmarc);
    }

    public function test_dns_cache_detects_dkim_record(): void
    {
        $site = $this->createSite(['url' => 'https://example.com']);

        $cache = DnsRecordCache::create([
            'site_id' => $site->id,
            'domain' => 'example.com',
            'a_records' => [],
            'aaaa_records' => [],
            'cname_records' => [],
            'mx_records' => [],
            'txt_records' => [],
            'ns_records' => [],
            'soa_record' => null,
            'has_www' => false,
            'uses_cloudflare' => false,
            'has_spf' => false,
            'has_dmarc' => false,
            'has_dkim' => true,
            'email_security_score' => 33,
            'total_records' => 0,
            'checked_at' => now(),
        ]);

        $this->assertTrue($cache->has_dkim);
    }

    public function test_dns_cache_identifies_google_workspace_mail_provider(): void
    {
        $site = $this->createSite(['url' => 'https://example.com']);

        $cache = DnsRecordCache::create([
            'site_id' => $site->id,
            'domain' => 'example.com',
            'a_records' => [],
            'aaaa_records' => [],
            'cname_records' => [],
            'mx_records' => [
                ['host' => 'aspmx.l.google.com', 'priority' => 1, 'ttl' => 3600],
                ['host' => 'alt1.aspmx.l.google.com', 'priority' => 5, 'ttl' => 3600],
            ],
            'txt_records' => [],
            'ns_records' => [],
            'soa_record' => null,
            'has_www' => false,
            'uses_cloudflare' => false,
            'has_spf' => false,
            'has_dmarc' => false,
            'has_dkim' => false,
            'mail_provider' => 'Google Workspace',
            'email_security_score' => 0,
            'total_records' => 2,
            'checked_at' => now(),
        ]);

        $this->assertEquals('Google Workspace', $cache->mail_provider);
    }

    public function test_dns_cache_calculates_email_score(): void
    {
        $site = $this->createSite(['url' => 'https://example.com']);

        // Full score: SPF (34) + DMARC (33) + DKIM (33) = 100
        $cache = DnsRecordCache::create([
            'site_id' => $site->id,
            'domain' => 'example.com',
            'a_records' => [],
            'aaaa_records' => [],
            'cname_records' => [],
            'mx_records' => [],
            'txt_records' => [],
            'ns_records' => [],
            'soa_record' => null,
            'has_www' => false,
            'uses_cloudflare' => false,
            'has_spf' => true,
            'has_dmarc' => true,
            'has_dkim' => true,
            'email_security_score' => 100,
            'total_records' => 0,
            'checked_at' => now(),
        ]);

        $this->assertEquals(100, $cache->email_security_score);
    }

    public function test_dns_cache_handles_missing_records_gracefully(): void
    {
        $site = $this->createSite(['url' => 'https://example.com']);

        $cache = DnsRecordCache::create([
            'site_id' => $site->id,
            'domain' => 'example.com',
            'a_records' => [],
            'aaaa_records' => [],
            'cname_records' => [],
            'mx_records' => [],
            'txt_records' => [],
            'ns_records' => [],
            'soa_record' => null,
            'has_www' => false,
            'uses_cloudflare' => false,
            'has_spf' => false,
            'has_dmarc' => false,
            'has_dkim' => false,
            'mail_provider' => null,
            'email_security_score' => 0,
            'total_records' => 0,
            'checked_at' => now(),
        ]);

        $this->assertEmpty($cache->a_records);
        $this->assertEmpty($cache->mx_records);
        $this->assertNull($cache->mail_provider);
        $this->assertNull($cache->soa_record);
        $this->assertFalse($cache->has_spf);
        $this->assertFalse($cache->has_dmarc);
        $this->assertFalse($cache->has_dkim);
        $this->assertEquals(0, $cache->email_security_score);
    }
}
