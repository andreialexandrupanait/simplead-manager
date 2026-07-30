<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Site;
use App\Services\UpdateSmokeCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Faza 6, SPEC §7.4 etapa 1 — post-update smoke check signals.
 *
 * Every case uses Http::fake so no real network is touched; the service must hit
 * the site's PUBLIC url directly (not the connector) and score the four signals.
 */
class UpdateSmokeCheckServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Site creation dispatches side-effect jobs (favicon, plan apply); keep
        // them off the wire so the only HTTP call is the smoke-check GET.
        Queue::fake();
    }

    private function site(string $url = 'https://example-smoke.test'): Site
    {
        return Site::factory()->create([
            'url' => $url,
            'smoke_canary_selector' => null,
            'smoke_dom_reference' => null,
        ]);
    }

    private function signal(array $result, string $name): array
    {
        foreach ($result['signals'] as $signal) {
            if ($signal['name'] === $name) {
                return $signal;
            }
        }

        $this->fail("Signal '{$name}' not present in result");
    }

    public function test_healthy_page_within_tolerance_passes(): void
    {
        $site = $this->site();
        $html = '<html><body><div id="app"><p>hello</p><span>ok</span></div></body></html>';
        Http::fake([$site->url => Http::response($html, 200)]);

        $service = new UpdateSmokeCheckService;
        // Reference equals the exact measured node count so it is trivially in-tolerance.
        $reference = substr_count($html, '<');

        $result = $service->run($site, $reference, '<body');

        $this->assertTrue($result['passed']);
        $this->assertTrue($this->signal($result, 'status')['passed']);
        $this->assertTrue($this->signal($result, 'no_fatal_error')['passed']);
        $this->assertTrue($this->signal($result, 'canary')['passed']);
        $this->assertTrue($this->signal($result, 'dom_nodes')['passed']);
    }

    public function test_fatal_error_in_html_fails(): void
    {
        $site = $this->site();
        $html = '<html><body>Fatal error: Uncaught Error in /wp-content/plugin.php:12</body></html>';
        Http::fake([$site->url => Http::response($html, 200)]);

        $result = (new UpdateSmokeCheckService)->run($site, substr_count($html, '<'), '<body');

        $this->assertFalse($result['passed']);
        $this->assertFalse($this->signal($result, 'no_fatal_error')['passed']);
    }

    public function test_parse_error_in_html_fails(): void
    {
        $site = $this->site();
        $html = '<html><body>Parse error: syntax error, unexpected token</body></html>';
        Http::fake([$site->url => Http::response($html, 200)]);

        $result = (new UpdateSmokeCheckService)->run($site, substr_count($html, '<'), '<body');

        $this->assertFalse($result['passed']);
        $this->assertFalse($this->signal($result, 'no_fatal_error')['passed']);
    }

    public function test_missing_canary_fails(): void
    {
        $site = $this->site();
        $html = '<html><body><div class="content">no marker here</div></body></html>';
        Http::fake([$site->url => Http::response($html, 200)]);

        $result = (new UpdateSmokeCheckService)->run($site, substr_count($html, '<'), '#unique-canary-marker');

        $this->assertFalse($result['passed']);
        $this->assertFalse($this->signal($result, 'canary')['passed']);
    }

    public function test_canary_present_passes_that_signal(): void
    {
        $site = $this->site();
        $html = '<html><body><div id="unique-canary-marker">present</div></body></html>';
        Http::fake([$site->url => Http::response($html, 200)]);

        $result = (new UpdateSmokeCheckService)->run($site, substr_count($html, '<'), 'unique-canary-marker');

        $this->assertTrue($this->signal($result, 'canary')['passed']);
    }

    public function test_dom_count_outside_tolerance_fails(): void
    {
        $site = $this->site();
        // ~6 '<' nodes but reference claims 100 → far outside ±20%.
        $html = '<html><body><p>tiny</p></body></html>';
        Http::fake([$site->url => Http::response($html, 200)]);

        $result = (new UpdateSmokeCheckService)->run($site, 100, '<body');

        $this->assertFalse($result['passed']);
        $this->assertFalse($this->signal($result, 'dom_nodes')['passed']);
    }

    public function test_status_500_fails(): void
    {
        $site = $this->site();
        $html = '<html><body>Service Unavailable</body></html>';
        Http::fake([$site->url => Http::response($html, 500)]);

        $result = (new UpdateSmokeCheckService)->run($site, substr_count($html, '<'), '<body');

        $this->assertFalse($result['passed']);
        $this->assertFalse($this->signal($result, 'status')['passed']);
    }

    public function test_baseline_run_passes_and_returns_measured_reference(): void
    {
        $site = $this->site();
        $html = '<html><body><div><p>content</p></div></body></html>';
        Http::fake([$site->url => Http::response($html, 200)]);

        // No reference passed → baseline run.
        $result = (new UpdateSmokeCheckService)->run($site, null, '<body');

        $this->assertTrue($result['passed']);
        $this->assertTrue($this->signal($result, 'dom_nodes')['passed']);
        $this->assertSame(substr_count($html, '<'), $result['dom_reference']);
    }

    public function test_unreachable_site_fails_safe(): void
    {
        $site = $this->site();
        // Force the HTTP layer to throw (connection exception) on the GET.
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
        });

        $result = (new UpdateSmokeCheckService)->run($site, 50, '<body');

        $this->assertFalse($result['passed']);
        $this->assertSame('unreachable', $result['signals'][0]['name']);
        // Reference is preserved unchanged when we never got HTML to measure.
        $this->assertSame(50, $result['dom_reference']);
    }
}
