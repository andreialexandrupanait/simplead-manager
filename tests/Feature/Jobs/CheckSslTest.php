<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\CheckSsl;
use App\Models\Site;
use App\Models\UptimeMonitor;
use App\Services\SiteTodoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P2-08: SSL-expiry monitoring was dead plumbing. This exercises the wired-up
 * queued check — the cert-fetch seam is overridden so the test is hermetic (no
 * live TLS connection): it injects a parsed certificate and asserts the expiry
 * is stored and near-expiry surfaces.
 */
class CheckSslTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $cert
     */
    private function jobWithCert(UptimeMonitor $monitor, array $cert): CheckSsl
    {
        return new class($monitor, $cert) extends CheckSsl
        {
            /** @var array<string, mixed> */
            public array $cert;

            public function __construct(UptimeMonitor $monitor, array $cert)
            {
                parent::__construct($monitor);
                $this->cert = $cert;
            }

            protected function fetchCertificate(string $host, int $port): array
            {
                return $this->cert;
            }
        };
    }

    public function test_populates_ssl_expires_at_from_the_certificate(): void
    {
        Queue::fake();

        $monitor = UptimeMonitor::factory()->create([
            'site_id' => Site::factory()->create()->id,
            'url' => 'https://example.test',
            'check_ssl' => true,
            'ssl_expiry_threshold' => 14,
        ]);

        $expiry = now()->addDays(60);

        $this->jobWithCert($monitor, [
            'validTo_time_t' => $expiry->getTimestamp(),
            'issuer' => ['O' => "Let's Encrypt"],
        ])->handle();

        $fresh = $monitor->fresh();

        $this->assertNotNull($fresh->ssl_expires_at);
        $this->assertSame($expiry->toDateString(), $fresh->ssl_expires_at->toDateString());
        $this->assertSame("Let's Encrypt", $fresh->ssl_issuer);
        $this->assertNull($fresh->ssl_last_error);
        $this->assertNotNull($fresh->ssl_last_checked_at);
        $this->assertTrue($fresh->next_ssl_check_at->isFuture());

        $this->assertFalse($fresh->sslIsExpiringSoon());
    }

    public function test_flags_near_expiry_and_surfaces_a_todo_item(): void
    {
        Queue::fake();

        $site = Site::factory()->create();
        $monitor = UptimeMonitor::factory()->create([
            'site_id' => $site->id,
            'url' => 'https://example.test',
            'check_ssl' => true,
            'ssl_expiry_threshold' => 14,
        ]);

        // Expires in 5 days — inside the 14-day warning window.
        $this->jobWithCert($monitor, [
            'validTo_time_t' => now()->addDays(5)->getTimestamp(),
            'issuer' => ['O' => 'Test CA'],
        ])->handle();

        $fresh = $monitor->fresh();
        $this->assertTrue($fresh->sslIsExpiringSoon());

        $todos = SiteTodoService::forSite($site->fresh()->load('uptimeMonitor'));
        $categories = array_column($todos, 'category');

        $this->assertContains('ssl', $categories);
    }

    public function test_transient_failure_preserves_last_known_expiry(): void
    {
        Queue::fake();

        $monitor = UptimeMonitor::factory()->create([
            'site_id' => Site::factory()->create()->id,
            'url' => 'https://example.test',
            'check_ssl' => true,
            'ssl_expires_at' => now()->addDays(30),
        ]);

        $failing = new class($monitor) extends CheckSsl
        {
            protected function fetchCertificate(string $host, int $port): array
            {
                throw new \RuntimeException('TLS handshake failed');
            }
        };

        $failing->handle();

        $fresh = $monitor->fresh();

        // Last-known expiry retained; only the error is recorded and cadence advances.
        $this->assertNotNull($fresh->ssl_expires_at);
        $this->assertNotNull($fresh->ssl_last_error);
        $this->assertTrue($fresh->next_ssl_check_at->isFuture());
    }
}
