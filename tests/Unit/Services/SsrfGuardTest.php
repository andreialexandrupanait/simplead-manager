<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\SsrfException;
use App\Services\Security\SsrfGuard;
use Tests\TestCase;

/**
 * P2-19: the SSRF guard must reject any user-supplied URL that resolves to a
 * private / loopback / link-local / reserved address, a non-http(s) scheme, or
 * an internal Docker service hostname — while letting legitimate public URLs
 * through. DNS is stubbed via a subclass so the test is hermetic.
 */
class SsrfGuardTest extends TestCase
{
    private function guard(array $resolved = ['93.184.216.34']): SsrfGuard
    {
        return new class($resolved) extends SsrfGuard
        {
            /** @param list<string> $resolved */
            public function __construct(private array $resolved) {}

            protected function resolveIps(string $host): array
            {
                // Literal IPs resolve to themselves (covers 10.x / 169.254.x tests);
                // everything else uses the injected stub.
                if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
                    return [$host];
                }

                return $this->resolved;
            }
        };
    }

    public function test_rejects_localhost(): void
    {
        $this->expectException(SsrfException::class);
        $this->guard()->assertPublicUrl('http://localhost');
    }

    public function test_rejects_cloud_metadata_endpoint(): void
    {
        $this->expectException(SsrfException::class);
        $this->guard()->assertPublicUrl('http://169.254.169.254/latest/meta-data/');
    }

    public function test_rejects_private_ip(): void
    {
        $this->expectException(SsrfException::class);
        $this->guard()->assertPublicUrl('http://10.1.2.3');
    }

    public function test_rejects_internal_docker_service_hostname(): void
    {
        $this->expectException(SsrfException::class);
        $this->guard()->assertPublicUrl('http://pgsql:5432');
    }

    public function test_rejects_non_http_scheme(): void
    {
        $this->expectException(SsrfException::class);
        $this->guard()->assertPublicUrl('file:///etc/passwd');
    }

    public function test_rejects_host_resolving_to_private_ip(): void
    {
        // A public-looking hostname that (maliciously) resolves internally.
        $this->expectException(SsrfException::class);
        $this->guard(['192.168.1.10'])->assertPublicUrl('https://evil.example.com');
    }

    public function test_allows_public_url(): void
    {
        $this->guard(['93.184.216.34'])->assertPublicUrl('https://example.com/path');
        $this->addToAssertionCount(1);
    }

    public function test_allowlist_escape_hatch_bypasses_guard(): void
    {
        config()->set('security.ssrf_allowed_hosts', ['internal-intake']);
        // Would otherwise be blocked (resolves private); allowlist lets it pass.
        $this->guard(['10.0.0.5'])->assertPublicUrl('https://internal-intake/hook');
        $this->addToAssertionCount(1);
    }

    public function test_is_public_ip_classification(): void
    {
        $guard = new SsrfGuard;

        $this->assertTrue($guard->isPublicIp('93.184.216.34'));
        $this->assertTrue($guard->isPublicIp('8.8.8.8'));

        $this->assertFalse($guard->isPublicIp('127.0.0.1'));
        $this->assertFalse($guard->isPublicIp('10.0.0.1'));
        $this->assertFalse($guard->isPublicIp('172.16.5.4'));
        $this->assertFalse($guard->isPublicIp('192.168.1.1'));
        $this->assertFalse($guard->isPublicIp('169.254.169.254'));
        $this->assertFalse($guard->isPublicIp('::1'));
        $this->assertFalse($guard->isPublicIp('not-an-ip'));
    }
}
