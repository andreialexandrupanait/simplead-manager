<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Plugin;

use App\Backup\V2\Plugin\SimpleadBackupClient;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SimpleadBackupClient::forSite — signs the plugin's HMAC namespace at the SITE's own
 * host, using the site's stored (encrypted-cast, auto-decrypted) connector credentials.
 */
class SimpleadBackupClientForSiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_forsite_signs_correct_url_with_the_sites_credentials(): void
    {
        $site = Site::factory()->create([
            'url' => 'https://client-site.example',
            'api_key' => 'conn-key-123',
            'api_secret' => 'conn-secret-456',
        ]);

        Http::fake([
            '*' => Http::response(['plugin' => ['version' => '1.0.0']], 200),
        ]);

        $client = SimpleadBackupClient::forSite($site);
        $client->capabilities();

        Http::assertSent(function (Request $request) use ($site): bool {
            // Correct URL: the site host + the plugin REST namespace.
            $this->assertSame(
                'https://client-site.example/wp-json/simplead-backup/v1/capabilities',
                $request->url(),
            );

            // Key header is the site's connector api_key (decrypted on read).
            $this->assertSame($site->api_key, $request->header('X-SAM-Backup-Key')[0]);

            // The signature validates against the site's api_secret over the exact
            // string_to_sign the plugin recomputes (METHOD|ROUTE|TS|NONCE|BODY).
            $timestamp = $request->header('X-SAM-Backup-Timestamp')[0];
            $nonce = $request->header('X-SAM-Backup-Nonce')[0];
            $route = '/simplead-backup/v1/capabilities';
            $stringToSign = implode('|', ['POST', $route, $timestamp, $nonce, '']);
            $expected = hash_hmac('sha256', $stringToSign, $site->api_secret);

            $this->assertSame($expected, $request->header('X-SAM-Backup-Signature')[0]);

            return true;
        });
    }

    /**
     * The wire name of the session id follows the probed plugin version: `run_id` from
     * 0.8.2 (feaa.ugal.ro's mod_security 403s any body with a key literally named
     * `session_id`), `session_id` for older plugins that only know the old name.
     */
    public function test_session_key_follows_probed_plugin_version(): void
    {
        foreach ([
            '0.8.0' => 'session_id',
            null => 'session_id',
            '0.8.2' => 'run_id',
            '0.9.0' => 'run_id',
        ] as $version => $expectedKey) {
            $site = Site::factory()->create([
                'api_key' => 'k',
                'api_secret' => 's',
                'backup_plugin_version' => $version === '' ? null : $version,
            ]);

            Http::fake(['*' => Http::response(['ok' => true], 200)]);

            SimpleadBackupClient::forSite($site)->filesChunkExec('sess-abc', 3);

            Http::assertSent(function (Request $request) use ($expectedKey): bool {
                $body = json_decode($request->body(), true);
                $this->assertSame('sess-abc', $body[$expectedKey] ?? null);
                $this->assertCount(1, array_intersect(array_keys($body), ['run_id', 'session_id']));

                return true;
            });
        }
    }
}
