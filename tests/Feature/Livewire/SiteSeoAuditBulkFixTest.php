<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Sites\Detail\SiteSeoAudit;
use App\Models\SeoAudit;
use App\Models\SeoIssue;
use App\Models\SeoPage;
use App\Models\Site;
use App\Models\User;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Audit E-10/E-34: bulkFix must never mass-flip noindex→index, never push
 * scraped-empty values over real content, must log what it applied, and must
 * talk to the connector through the signed HMAC client.
 */
class SiteSeoAuditBulkFixTest extends TestCase
{
    use RefreshDatabase;

    private function seedAudit(Site $site, array $pageAttrs, string $issueTitle): void
    {
        $audit = SeoAudit::create([
            'site_id' => $site->id,
            'status' => 'completed',
            'score' => 80,
            'scanned_at' => now(),
        ]);

        SeoPage::create(array_merge([
            'seo_audit_id' => $audit->id,
            'site_id' => $site->id,
            'url' => 'https://acme.com/p1',
            'url_hash' => md5('https://acme.com/p1'),
            'status_code' => 200,
        ], $pageAttrs));

        SeoIssue::create([
            'seo_audit_id' => $audit->id,
            'site_id' => $site->id,
            'category' => 'on_page',
            'severity' => 'high',
            'title' => $issueTitle,
            'url' => 'https://acme.com/p1',
        ]);
    }

    public function test_noindex_issues_are_not_bulk_fixable(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $site = Site::factory()->create(['user_id' => $manager->id, 'is_connected' => true]);

        $component = Livewire::actingAs($manager)
            ->test(SiteSeoAudit::class, ['site' => $site]);

        $fixable = $component->instance()->fixableIssueTitles();

        $this->assertArrayNotHasKey('Page set to noindex', $fixable);
        $this->assertArrayNotHasKey('Noindex page in sitemap', $fixable);
    }

    public function test_bulk_fix_skips_pages_with_no_safe_value_to_write(): void
    {
        $fake = $this->fakeApi();

        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $site = Site::factory()->create(['user_id' => $manager->id, 'is_connected' => true]);

        // Crawler captured nothing — pushing would blank real WP content.
        $this->seedAudit($site, ['title' => null, 'meta_description' => null], 'Missing title tag');

        Livewire::actingAs($manager)
            ->test(SiteSeoAudit::class, ['site' => $site])
            ->call('bulkFix', 'Missing title tag');

        $this->assertSame([], $fake->callsTo('request'));
    }

    public function test_bulk_fix_sends_only_non_empty_fields_through_the_signed_client(): void
    {
        $fake = $this->fakeApi();
        $fake->script('request', new Response(new Psr7Response(200, [], (string) json_encode(['success' => true]))));

        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $site = Site::factory()->create(['user_id' => $manager->id, 'is_connected' => true]);

        $this->seedAudit($site, ['title' => 'Hello World', 'meta_description' => null], 'Title too short');

        Livewire::actingAs($manager)
            ->test(SiteSeoAudit::class, ['site' => $site])
            ->call('bulkFix', 'Title too short');

        $calls = $fake->callsTo('request');
        $this->assertCount(1, $calls);

        [$method, $endpoint, $payload] = $calls[0]['args'];
        $this->assertSame('POST', $method);
        $this->assertSame('/seo/update-meta', $endpoint);
        $this->assertSame('Hello World', $payload['meta_title']);
        $this->assertArrayNotHasKey('meta_description', $payload, 'empty scraped values must not be pushed');

        $this->assertDatabaseHas('activity_logs', [
            'site_id' => $site->id,
            'type' => 'seo',
        ]);
    }
}
