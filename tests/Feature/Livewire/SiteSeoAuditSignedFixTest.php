<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Jobs\RunSeoAudit;
use App\Livewire\Sites\Detail\SiteSeoAudit;
use App\Models\SeoAudit;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Audit P1-03/F-SEO-02: the single-page SEO fix actions and the audit
 * enrichment fetch previously sent only an `X-SAM-API-Key` header, which the
 * connector never reads — every request 401'd. They must now go through the
 * signed HMAC client (X-SAM-Key + timestamp + nonce + signature).
 *
 * Audit P0-15/F-SEO-01: the decrypted api_key must never be emitted into the
 * SEO page HTML/JS.
 */
class SiteSeoAuditSignedFixTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'sam_live_secretkey_abcdef123456';

    private const API_SECRET = 'sam_secret_zyxwvu987654';

    private function connectedSite(User $owner): Site
    {
        return Site::factory()->create([
            'user_id' => $owner->id,
            'url' => 'https://acme.example',
            'is_connected' => true,
            'is_prospect' => false,
            'api_key' => self::API_KEY,
            'api_secret' => self::API_SECRET,
            'api_endpoint' => null,
        ]);
    }

    /**
     * A recorded request carries the signed HMAC headers and never the dead
     * X-SAM-API-Key header.
     */
    private function assertSignedRequest(Request $request, string $method, string $endpointFragment): bool
    {
        return $request->method() === $method
            && str_contains($request->url(), $endpointFragment)
            && $request->hasHeader('X-SAM-Key')
            && $request->hasHeader('X-SAM-Timestamp')
            && $request->hasHeader('X-SAM-Nonce')
            && $request->hasHeader('X-SAM-Signature')
            && ! $request->hasHeader('X-SAM-API-Key');
    }

    public function test_push_meta_fix_uses_signed_client(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['success' => true], 200)]);

        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $site = $this->connectedSite($manager);

        Livewire::actingAs($manager)
            ->test(SiteSeoAudit::class, ['site' => $site])
            ->set('fixUrl', 'https://acme.example/p1')
            ->set('fixTitle', 'New Title')
            ->set('fixDescription', 'New description')
            ->call('pushMetaFix');

        Http::assertSent(fn (Request $r) => $this->assertSignedRequest($r, 'POST', '/seo/update-meta'));
        Http::assertNotSent(fn (Request $r) => $r->hasHeader('X-SAM-API-Key'));
    }

    public function test_push_robots_fix_uses_signed_client(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['success' => true], 200)]);

        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $site = $this->connectedSite($manager);

        Livewire::actingAs($manager)
            ->test(SiteSeoAudit::class, ['site' => $site])
            ->set('fixRobotsUrl', 'https://acme.example/p1')
            ->set('fixRobotsAction', 'index')
            ->call('pushRobotsFix');

        Http::assertSent(fn (Request $r) => $this->assertSignedRequest($r, 'POST', '/seo/update-robots'));
    }

    public function test_push_canonical_fix_uses_signed_client(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['success' => true], 200)]);

        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $site = $this->connectedSite($manager);

        Livewire::actingAs($manager)
            ->test(SiteSeoAudit::class, ['site' => $site])
            ->set('fixCanonicalUrl', 'https://acme.example/p1')
            ->set('fixCanonicalTarget', 'https://acme.example/p1')
            ->call('pushCanonicalFix');

        Http::assertSent(fn (Request $r) => $this->assertSignedRequest($r, 'POST', '/seo/update-canonical'));
    }

    public function test_push_og_fix_uses_signed_client(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['success' => true], 200)]);

        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $site = $this->connectedSite($manager);

        Livewire::actingAs($manager)
            ->test(SiteSeoAudit::class, ['site' => $site])
            ->set('fixOgUrl', 'https://acme.example/p1')
            ->set('fixOgTitle', 'OG Title')
            ->set('fixOgDescription', 'OG desc')
            ->set('fixOgImage', 'https://acme.example/img.png')
            ->call('pushOgFix');

        Http::assertSent(fn (Request $r) => $this->assertSignedRequest($r, 'POST', '/seo/update-og'));
    }

    public function test_toggle_search_visibility_uses_signed_client(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['success' => true], 200)]);

        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $site = $this->connectedSite($manager);

        Livewire::actingAs($manager)
            ->test(SiteSeoAudit::class, ['site' => $site])
            ->call('toggleSearchVisibility');

        Http::assertSent(fn (Request $r) => $this->assertSignedRequest($r, 'POST', '/seo/toggle-search-visibility'));
    }

    public function test_run_seo_audit_enrichment_fetch_uses_signed_client(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response([
            'data' => [
                'seo_plugin' => ['name' => 'Yoast SEO', 'version' => '22.0'],
                'search_visibility' => true,
                'redirects' => ['plugin' => 'Rank Math'],
            ],
        ], 200)]);

        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $site = $this->connectedSite($manager);

        $audit = SeoAudit::create([
            'site_id' => $site->id,
            'status' => 'crawling',
            'score' => 0,
        ]);

        (new RunSeoAudit($site, $audit))->handle();

        Http::assertSent(fn (Request $r) => $this->assertSignedRequest($r, 'GET', '/seo/analysis'));

        // Enrichment now actually populates (previously starved by the 401).
        $audit->refresh();
        $this->assertSame('Yoast SEO', $audit->seo_plugin);
        $this->assertSame('Rank Math', $audit->redirect_info['plugin'] ?? null);
    }

    public function test_seo_page_does_not_expose_decrypted_api_key(): void
    {
        Queue::fake();

        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $site = $this->connectedSite($manager);

        Livewire::actingAs($manager)
            ->test(SiteSeoAudit::class, ['site' => $site])
            ->set('activeTab', 'redirects')
            ->assertDontSee(self::API_KEY)
            ->assertDontSee('X-SAM-API-Key');
    }
}
