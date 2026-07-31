<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

/**
 * The connector zip is built on the fly from wordpress-plugin/ — there is no
 * artefact on disk to notice going missing. It is also the first thing anyone
 * connecting a site needs, and the credentials that screen asks for cannot be
 * obtained without it, so a broken route here is a broken onboarding.
 */
class ConnectorPluginDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authenticated_user_gets_the_plugin_zip(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->get(route('download.connector-plugin'))
            ->assertOk()
            ->assertDownload('simplead-manager-connector.zip');
    }

    public function test_the_zip_actually_contains_the_plugin(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $response = $this->get(route('download.connector-plugin'));
        $response->assertOk();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($response->getFile()->getPathname()) === true);

        try {
            $this->assertNotFalse(
                $zip->locateName('simplead-manager-connector/simplead-manager-connector.php'),
                'The zip is missing the plugin main file.',
            );
            $this->assertGreaterThan(1, $zip->numFiles, 'The zip holds no plugin sources.');
        } finally {
            $zip->close();
        }
    }

    /**
     * The ETag sits next to a one-hour Cache-Control, so it has to track the
     * version actually being served — otherwise a cache can hand back a stale
     * zip across a version bump.
     */
    public function test_the_etag_carries_the_served_plugin_version(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $head = (string) file_get_contents(
            base_path('wordpress-plugin/simplead-manager-connector/simplead-manager-connector.php'),
            false, null, 0, 2048,
        );
        preg_match('/^\s*\*\s*Version:\s*(.+)$/mi', $head, $m);
        $version = trim($m[1] ?? '');

        $this->assertNotSame('', $version, 'The plugin header has no Version:.');
        $this->get(route('download.connector-plugin'))
            ->assertHeader('ETag', '"connector-'.$version.'"');
    }

    public function test_a_guest_is_not_served_the_plugin(): void
    {
        $this->get(route('download.connector-plugin'))->assertRedirect(route('login'));
    }
}
