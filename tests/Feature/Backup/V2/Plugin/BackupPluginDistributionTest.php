<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Plugin;

use App\Contracts\WordPressApiServiceInterface;
use App\Models\Site;
use App\Services\Backup\BackupPluginInstaller;
use App\Services\WordPressApiServiceFactory;
use App\Support\PluginPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use ZipArchive;

/**
 * How the V2 backup engine gets onto a site.
 *
 * Until this existed it did not get onto a site at all: somebody downloaded the zip and uploaded it
 * through wp-admin. It ran on one site out of twenty-four, and every fix to it — including the ones
 * that stop a restore filling a client's disk — waited at the edge of the fleet for a manual step.
 */
#[Group('backup-v2')]
class BackupPluginDistributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_package_is_a_zip_wordpress_can_install(): void
    {
        $package = PluginPackage::backupEngine();
        $path = $package->buildZip();

        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path) === true, 'the package must open as a zip');

            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $names[] = (string) $zip->getNameIndex($i);
            }
            $zip->close();

            // WordPress installs a plugin by its folder, and the folder has to be the slug — an
            // archive of loose files lands in the wrong place or not at all.
            $this->assertContains('simplead-backup/simplead-backup.php', $names);
            $this->assertContains('simplead-backup/includes/restore/class-restore-engine.php', $names);
            foreach ($names as $name) {
                $this->assertStringStartsWith('simplead-backup/', $name);
            }
        } finally {
            @unlink($path);
        }
    }

    public function test_two_builds_of_the_same_source_hash_the_same(): void
    {
        // The site refuses a package whose hash does not match what we promised. If the builder is
        // not deterministic, that refusal happens at random, on someone else's server, and looks
        // like a corrupted download.
        $package = PluginPackage::backupEngine();

        $this->assertSame($package->hash(), $package->hash());
    }

    public function test_the_version_served_is_the_version_in_the_plugin_header(): void
    {
        $package = PluginPackage::backupEngine();
        $header = (string) file_get_contents(base_path('wordpress-plugin/simplead-backup/simplead-backup.php'), false, null, 0, 8192);

        preg_match('/^[ \t\/*#@]*Version:\s*(.+?)$/mi', $header, $m);

        $this->assertSame(trim($m[1] ?? ''), $package->version());
        $this->assertNotSame('0.0.0', $package->version());
    }

    public function test_the_download_needs_a_signature(): void
    {
        $this->get('/download/backup-plugin/signed')->assertForbidden();
    }

    public function test_a_signed_link_serves_the_plugin(): void
    {
        $url = URL::temporarySignedRoute('download.backup-plugin.signed', now()->addMinutes(5));

        $response = $this->get($url);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/zip');
        $this->assertStringContainsString(
            PluginPackage::backupEngine()->version(),
            (string) $response->headers->get('ETag'),
            'the ETag carries the version so a cache cannot serve last week\'s zip after a bump',
        );
    }

    public function test_the_site_is_told_what_to_fetch_and_what_it_should_hash_to(): void
    {
        $site = Site::factory()->create();
        $sent = [];
        $this->engineAnswers('9.9.9');

        $installer = new BackupPluginInstaller($this->factoryRecording($sent, [
            'success' => true, 'installed' => true, 'new_version' => '9.9.9', 'active' => true,
        ]));

        $result = $installer->install($site);

        $this->assertTrue($result['ok']);
        $this->assertSame('/plugins/install-package', $sent['endpoint']);
        $this->assertSame('simplead-backup', $sent['payload']['slug']);
        $this->assertSame(PluginPackage::backupEngine()->hash(), $sent['payload']['expected_hash']);
        $this->assertStringContainsString('signature=', $sent['payload']['download_url']);
    }

    public function test_an_old_connector_is_named_rather_than_reported_as_http_404(): void
    {
        // This endpoint arrives in connector 2.25.0. A site on an older one answers 404 for a route
        // it has never heard of, and "HTTP 404" sends whoever reads it looking at the wrong thing.
        $site = Site::factory()->create();
        $sent = [];

        $installer = new BackupPluginInstaller($this->factoryRecording($sent, ['success' => false], status: 404));

        $result = $installer->install($site);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('connector', $result['message']);
        $this->assertStringContainsString('2.25.0', $result['message']);
    }

    public function test_a_plugin_that_installs_but_will_not_activate_is_not_reported_as_fine(): void
    {
        // Present but inactive is the worst of both: the files are there, the site answers nothing,
        // and a backup fails at the first call with something that reads like a network problem.
        $site = Site::factory()->create();
        $sent = [];
        $this->engineAnswers('0.7.1');

        $installer = new BackupPluginInstaller($this->factoryRecording($sent, [
            'success' => true,
            'installed' => true,
            'new_version' => '0.7.1',
            'active' => false,
            'activation_error' => 'plugin file does not exist',
        ]));

        $result = $installer->install($site);

        $this->assertStringContainsString('NOT ACTIVE', $result['message']);
    }

    /**
     * The engine, answering. install() asks it directly after the connector reports success —
     * "the files are in place" and "the manager can reach them" are different claims, and treating
     * the first as the second is what left thirteen sites recorded as migrated and mute.
     */
    private function engineAnswers(string $version): void
    {
        Http::fake([
            '*/wp-json/simplead-backup/v1/capabilities' => Http::response([
                'plugin' => ['name' => 'simplead-backup', 'version' => $version],
            ], 200),
        ]);
    }

    /**
     * @param  array<string, mixed>  $sent  filled in with what the connector was asked to do
     * @param  array<string, mixed>  $body
     */
    private function factoryRecording(array &$sent, array $body, int $status = 200): WordPressApiServiceFactory
    {
        $api = $this->createMock(WordPressApiServiceInterface::class);
        $api->method('request')->willReturnCallback(
            function (string $method, string $endpoint, array $payload = [], array $headers = [], int $timeout = 30) use (&$sent, $body, $status): Response {
                $sent = ['method' => $method, 'endpoint' => $endpoint, 'payload' => $payload];

                return new Response(new \GuzzleHttp\Psr7\Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body)));
            },
        );

        $factory = $this->createMock(WordPressApiServiceFactory::class);
        $factory->method('make')->willReturn($api);

        return $factory;
    }
}
