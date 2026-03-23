<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    // --- 1. Security Headers ---

    #[Test]
    public function security_headers_present_on_unauthenticated_routes(): void
    {
        $response = $this->get('/login');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('X-XSS-Protection', '1; mode=block');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    #[Test]
    public function security_headers_present_on_authenticated_routes(): void
    {
        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('X-XSS-Protection', '1; mode=block');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    // --- 2. Content Security Policy ---

    #[Test]
    public function csp_header_contains_all_directives_with_nonce(): void
    {
        $response = $this->get('/login');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp, 'CSP header should be present');

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringContainsString("'unsafe-eval'", $csp);
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline'", $csp);
        $this->assertStringContainsString("img-src 'self' data: blob: https:", $csp);
        $this->assertStringContainsString("font-src 'self' data:", $csp);
        $this->assertStringContainsString("connect-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);

        $this->assertMatchesRegularExpression('/nonce-[A-Za-z0-9]{32}/', $csp);
    }

    #[Test]
    public function csp_nonce_is_unique_per_request(): void
    {
        $response1 = $this->get('/login');
        $response2 = $this->get('/login');

        $csp1 = $response1->headers->get('Content-Security-Policy');
        $csp2 = $response2->headers->get('Content-Security-Policy');

        preg_match('/nonce-([A-Za-z0-9]{32})/', $csp1, $matches1);
        preg_match('/nonce-([A-Za-z0-9]{32})/', $csp2, $matches2);

        $this->assertNotEmpty($matches1[1] ?? null, 'First request should contain a nonce');
        $this->assertNotEmpty($matches2[1] ?? null, 'Second request should contain a nonce');
        $this->assertNotEquals($matches1[1], $matches2[1], 'Nonces should differ between requests');
    }

    // --- 3. HSTS ---

    #[Test]
    public function hsts_header_present_on_https(): void
    {
        $response = $this->call('GET', '/login', server: ['HTTPS' => 'on']);

        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
    }

    #[Test]
    public function hsts_header_absent_on_http(): void
    {
        $response = $this->call('GET', 'http://localhost/login');

        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    // --- 4. Login Rate Limiting ---

    #[Test]
    public function login_rate_limit_blocks_after_five_attempts(): void
    {
        $payload = [
            'email' => 'test@example.com',
            'password' => 'wrong-password',
        ];

        for ($i = 1; $i <= 5; $i++) {
            $response = $this->post('/login', $payload);
            $this->assertNotEquals(429, $response->getStatusCode(), "Request {$i} should not be rate limited");
        }

        $response = $this->post('/login', $payload);
        $this->assertEquals(429, $response->getStatusCode(), '6th request should be rate limited');
    }

    // --- 5. Agent HMAC Authentication ---

    #[Test]
    public function agent_rejects_invalid_site_token(): void
    {
        $response = $this->getJson('/api/agent/'.Str::random(32).'/security/pending-commands');

        $response->assertStatus(401);
        $response->assertJson(['error' => 'Invalid site token.']);
    }

    #[Test]
    public function agent_rejects_missing_signature_headers(): void
    {
        [$site, $apiKey, $apiSecret] = $this->createSiteWithRawCredentials();

        $response = $this->getJson('/api/agent/'.$apiKey.'/security/pending-commands');

        $response->assertStatus(401);
        $response->assertJson(['error' => 'Missing signature or timestamp.']);
    }

    #[Test]
    public function agent_rejects_expired_timestamp(): void
    {
        [$site, $apiKey, $apiSecret] = $this->createSiteWithRawCredentials();

        $timestamp = (string) (time() - 301);
        $headers = $this->generateHmacHeaders($apiSecret, '[]', $timestamp);

        $response = $this->getJson('/api/agent/'.$apiKey.'/security/pending-commands', $headers);

        $response->assertStatus(401);
        $response->assertJson(['error' => 'Request timestamp expired.']);
    }

    #[Test]
    public function agent_rejects_invalid_signature(): void
    {
        [$site, $apiKey, $apiSecret] = $this->createSiteWithRawCredentials();

        $timestamp = (string) time();
        $headers = $this->generateHmacHeaders('wrong-secret', '[]', $timestamp);

        $response = $this->getJson('/api/agent/'.$apiKey.'/security/pending-commands', $headers);

        $response->assertStatus(401);
        $response->assertJson(['error' => 'Invalid signature.']);
    }

    #[Test]
    public function agent_accepts_valid_hmac_signature(): void
    {
        [$site, $apiKey, $apiSecret] = $this->createSiteWithRawCredentials();

        $timestamp = (string) time();
        $headers = $this->generateHmacHeaders($apiSecret, '[]', $timestamp);

        $response = $this->getJson('/api/agent/'.$apiKey.'/security/pending-commands', $headers);

        $this->assertNotEquals(401, $response->getStatusCode(), 'Valid HMAC should not return 401');
    }

    // --- 6. Session Configuration ---

    #[Test]
    public function session_configuration_is_secure(): void
    {
        $this->assertTrue(config('session.http_only'), 'Session cookies should be HTTP-only');
        $this->assertEquals('lax', config('session.same_site'), 'Session same_site should be lax');
    }

    // --- Helpers ---

    private function createSiteWithRawCredentials(): array
    {
        $user = User::factory()->create();
        $apiKey = 'test-api-key-'.Str::random(16);
        $apiSecret = 'test-api-secret-'.Str::random(16);

        $site = Site::factory()->for($user)->create();

        // Store raw api_key for WHERE matching, and encrypt api_secret
        // so the encrypted cast can decrypt it in the middleware
        DB::table('sites')->where('id', $site->id)->update([
            'api_key' => $apiKey,
            'api_secret' => Crypt::encryptString($apiSecret),
        ]);

        return [$site->fresh(), $apiKey, $apiSecret];
    }

    private function generateHmacHeaders(string $secret, string $body, string $timestamp): array
    {
        $payload = $timestamp.'.'.$body;
        $signature = hash_hmac('sha256', $payload, $secret);

        return [
            'X-Signature' => $signature,
            'X-Timestamp' => $timestamp,
        ];
    }
}
