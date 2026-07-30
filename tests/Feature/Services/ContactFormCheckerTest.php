<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Contracts\WordPressApiServiceInterface;
use App\Models\Site;
use App\Models\SitePlugin;
use App\Services\ContactFormChecker;
use App\Services\WordPressApiServiceFactory;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Tests\TestCase;

/**
 * Covers the allowlist + fail-safe logic without a real WordPress host. The
 * connector is mocked; the real proof of suppression is done on the owner-gated
 * pilot.
 */
class ContactFormCheckerTest extends TestCase
{
    use RefreshDatabase;

    private function makePlugin(Site $site, string $slug, string $name, bool $active = true): SitePlugin
    {
        return SitePlugin::factory()->create([
            'site_id' => $site->id,
            'slug' => $slug,
            'name' => $name,
            'file' => $slug.'/'.$slug.'.php',
            'is_active' => $active,
        ]);
    }

    private function jsonResponse(array $data): Response
    {
        return new Response(new Psr7Response(200, [], (string) json_encode($data)));
    }

    /** Build a checker whose mock connector records every endpoint it is asked for. */
    private function checkerRecording(array &$calls, array $returns = []): ContactFormChecker
    {
        $api = $this->createMock(WordPressApiServiceInterface::class);
        $api->method('request')->willReturnCallback(
            function (string $method, string $endpoint) use (&$calls, $returns) {
                $calls[] = $endpoint;

                return $returns[$endpoint] ?? $this->jsonResponse(['success' => true]);
            }
        );

        $factory = $this->createMock(WordPressApiServiceFactory::class);
        $factory->method('make')->willReturn($api);

        return new ContactFormChecker($factory);
    }

    public function test_unknown_plugin_capability_is_unsupported_and_does_not_probe_connector(): void
    {
        $site = Site::factory()->create();
        $this->makePlugin($site, 'contact-form-7', 'Contact Form 7');

        $calls = [];
        $checker = $this->checkerRecording($calls);

        $result = $checker->capability($site);

        $this->assertFalse($result['supported']);
        $this->assertSame('Contact Form 7', $result['form_plugin']);
        // Fail-safe: an unsupported site is never even probed.
        $this->assertSame([], $calls);

        $this->assertDatabaseHas('form_checks', [
            'site_id' => $site->id,
            'supported' => false,
        ]);
    }

    public function test_no_form_plugin_capability_is_unsupported(): void
    {
        $site = Site::factory()->create();
        $this->makePlugin($site, 'woocommerce', 'WooCommerce');

        $calls = [];
        $checker = $this->checkerRecording($calls);

        $result = $checker->capability($site);

        $this->assertFalse($result['supported']);
        $this->assertNull($result['form_plugin']);
        $this->assertSame([], $calls);
    }

    public function test_unknown_plugin_run_gated_test_refuses_and_never_calls_run(): void
    {
        $site = Site::factory()->create();
        $this->makePlugin($site, 'contact-form-7', 'Contact Form 7');

        $calls = [];
        $checker = $this->checkerRecording($calls);

        try {
            $checker->runGatedTest($site);
            $this->fail('Expected runGatedTest to refuse an unsupported plugin.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('allowlist', $e->getMessage());
        }

        // The core fail-safe: /form-test/run must NEVER be called.
        $this->assertNotContains('/form-test/run', $calls);

        $this->assertDatabaseHas('form_checks', [
            'site_id' => $site->id,
            'supported' => false,
            'status' => 'refused_unsupported',
        ]);
    }

    public function test_known_plugin_capability_is_supported(): void
    {
        $site = Site::factory()->create();
        $this->makePlugin($site, 'wpforms-lite', 'WPForms Lite');

        $calls = [];
        $checker = $this->checkerRecording($calls, [
            '/form-test/capability' => $this->jsonResponse([
                'success' => true,
                'form_plugin' => 'WPForms',
                'supported' => true,
                'active' => true,
            ]),
        ]);

        $result = $checker->capability($site);

        $this->assertTrue($result['supported']);
        $this->assertSame('WPForms', $result['form_plugin']);
        // A supported plugin IS probed via the safe capability endpoint.
        $this->assertSame(['/form-test/capability'], $calls);
        $this->assertSame(true, $result['connector']['supported']);

        $this->assertDatabaseHas('form_checks', [
            'site_id' => $site->id,
            'supported' => true,
            'status' => 'capability_supported',
        ]);
    }

    public function test_known_plugin_run_gated_test_calls_run(): void
    {
        $site = Site::factory()->create();
        $this->makePlugin($site, 'gravityforms', 'Gravity Forms');

        $calls = [];
        $checker = $this->checkerRecording($calls, [
            '/form-test/capability' => $this->jsonResponse([
                'success' => true, 'form_plugin' => 'Gravity Forms', 'supported' => true,
            ]),
            '/form-test/run' => $this->jsonResponse([
                'success' => true,
                'supported' => true,
                'submitted' => true,
                'suppression_confirmed' => true,
                'form_plugin' => 'Gravity Forms',
            ]),
        ]);

        $data = $checker->runGatedTest($site);

        $this->assertTrue($data['submitted']);
        $this->assertContains('/form-test/run', $calls);

        $this->assertDatabaseHas('form_checks', [
            'site_id' => $site->id,
            'supported' => true,
            'suppression_confirmed' => true,
            'status' => 'submitted',
        ]);
    }
}
