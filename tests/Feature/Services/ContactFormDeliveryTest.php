<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Contracts\WordPressApiServiceInterface;
use App\Jobs\CheckContactForms;
use App\Models\FormCheck;
use App\Models\Site;
use App\Models\SitePlugin;
use App\Services\ContactFormChecker;
use App\Services\WordPressApiServiceFactory;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * SPEC §5.4: "trimitere reală săptămânală, cu marcaj, plus verificare de livrare".
 *
 * The service existed with zero callers, and the delivery half was impossible as
 * built: the connector aborted the marked notification, so no message survived to
 * be verified. Connector 2.21.0 redirects it to a test mailbox instead.
 */
class ContactFormDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        Config::set('monitoring.form_test.deliver_to', 'andrei.panait@simplead.ro');
        Config::set('monitoring.form_test.name', 'SimpleAD TEST');
        Config::set('monitoring.form_test.webhook_secret', 'hunter2');

        $this->site = Site::factory()->create([
            'is_connected' => true,
            'url' => 'https://shop.example.com',
            'connector_version' => '2.21.0',
        ]);

        // A plugin from the suppression allowlist, so the test is not refused.
        SitePlugin::factory()->create([
            'site_id' => $this->site->id,
            'slug' => 'wpforms',
            'name' => 'WPForms',
            'is_active' => true,
        ]);
    }

    private function jsonResponse(array $data): Response
    {
        return new Response(new Psr7Response(200, [], (string) json_encode($data)));
    }

    /**
     * A checker whose connector is mocked, recording the payload of every call.
     * The connector client uses its own Guzzle stack rather than the Http facade,
     * so faking at the interface is the only thing that intercepts it.
     *
     * @param  array<int, array{endpoint: string, data: array}>  $calls
     */
    private function checkerRecording(array &$calls): ContactFormChecker
    {
        $api = $this->createMock(WordPressApiServiceInterface::class);
        $api->method('request')->willReturnCallback(
            function (string $method, string $endpoint, array $data = []) use (&$calls) {
                $calls[] = ['endpoint' => $endpoint, 'data' => $data];

                return $this->jsonResponse([
                    'supported' => true,
                    'submitted' => true,
                    'suppression_confirmed' => true,
                ]);
            }
        );

        $factory = $this->createMock(WordPressApiServiceFactory::class);
        $factory->method('make')->willReturn($api);

        return new ContactFormChecker($factory);
    }

    public function test_the_run_asks_the_connector_to_redirect_the_notification(): void
    {
        $calls = [];
        $this->checkerRecording($calls)->runGatedTest($this->site);

        $run = collect($calls)->firstWhere('endpoint', '/form-test/run');

        $this->assertNotNull($run, 'The gated run never reached the connector.');
        // Without these the connector falls back to killing the mail, and the
        // delivery question becomes unanswerable again.
        $this->assertSame('andrei.panait@simplead.ro', $run['data']['deliver_to'] ?? null);
        $this->assertSame('SimpleAD TEST', $run['data']['test_name'] ?? null);
    }

    public function test_an_older_connector_is_not_sent_the_redirect_parameters(): void
    {
        $this->site->update(['connector_version' => '2.20.0']);

        $calls = [];
        $this->checkerRecording($calls)->runGatedTest($this->site);

        $run = collect($calls)->firstWhere('endpoint', '/form-test/run');

        $this->assertNotNull($run);
        // 2.20.0 ignores unknown parameters and aborts the mail as before; sending
        // them would only imply a redirect that is not going to happen.
        $this->assertArrayNotHasKey('deliver_to', $run['data']);
    }

    public function test_a_site_can_override_the_delivery_address(): void
    {
        $this->site->update(['form_test_email' => 'forms@client.example']);

        $this->assertSame(
            'forms@client.example',
            app(ContactFormChecker::class)->deliveryAddress($this->site)
        );
    }

    public function test_the_weekly_sweep_skips_sites_whose_plugin_cannot_be_suppressed(): void
    {
        $unsupported = Site::factory()->create(['is_connected' => true, 'connector_version' => '2.21.0']);
        SitePlugin::factory()->create([
            'site_id' => $unsupported->id,
            'slug' => 'contact-form-7',
            'name' => 'Contact Form 7',
            'is_active' => true,
        ]);

        $calls = [];
        (new CheckContactForms)->handle($this->checkerRecording($calls));

        // The fail-safe refuses an unsupported plugin before any submit, so exactly
        // one site is posted to — the whole reason a weekly schedule is safe at all.
        $runs = collect($calls)->where('endpoint', '/form-test/run');
        $this->assertCount(1, $runs);

        $this->assertSame(
            'submitted',
            FormCheck::where('site_id', $this->site->id)->first()->status
        );
        $this->assertSame(
            'refused_unsupported',
            FormCheck::where('site_id', $unsupported->id)->first()->status
        );
    }

    public function test_the_delivery_hook_verifies_the_matching_site(): void
    {
        FormCheck::create([
            'site_id' => $this->site->id,
            'form_plugin' => 'WPForms',
            'supported' => true,
            'submitted_at' => now()->subMinutes(2),
            'checked_at' => now()->subMinutes(2),
        ]);

        $this->postJson(route('webhooks.form-test-delivery'), [
            'subject' => '[TEST] New form entry',
            'text' => 'Email: sam+samtest@shop.example.com',
        ], ['X-Form-Test-Secret' => 'hunter2'])->assertOk();

        $this->assertTrue(FormCheck::where('site_id', $this->site->id)->first()->delivery_verified);
    }

    public function test_the_delivery_hook_rejects_a_wrong_secret(): void
    {
        $this->postJson(route('webhooks.form-test-delivery'), [
            'text' => 'sam+samtest@shop.example.com',
        ], ['X-Form-Test-Secret' => 'wrong'])->assertUnauthorized();
    }

    /**
     * An archived or replayed message must not verify a form that has been broken
     * for months.
     */
    public function test_an_old_submission_is_not_verified_by_a_late_message(): void
    {
        FormCheck::create([
            'site_id' => $this->site->id,
            'form_plugin' => 'WPForms',
            'supported' => true,
            'submitted_at' => now()->subDays(9),
            'checked_at' => now()->subDays(9),
        ]);

        $this->postJson(route('webhooks.form-test-delivery'), [
            'text' => 'sam+samtest@shop.example.com',
        ], ['X-Form-Test-Secret' => 'hunter2'])->assertStatus(202);

        $this->assertFalse(FormCheck::where('site_id', $this->site->id)->first()->delivery_verified);
    }
}
