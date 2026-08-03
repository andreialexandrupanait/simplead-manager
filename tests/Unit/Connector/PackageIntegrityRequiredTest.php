<?php

declare(strict_types=1);

namespace Tests\Unit\Connector;

use PHPUnit\Framework\TestCase;
use SAM_Package_Install_Endpoint;
use WP_REST_Request;

/**
 * The two endpoints that install code on a client site must refuse an unverified package.
 *
 * Both wrote the integrity check as `if a hash was supplied, check it`, while the endpoint's own
 * docblock justified its existence on the package being verified against a hash the manager
 * computed — the guarantee that keeps a leaked API key at "read the site" instead of "own the
 * server". A check that only runs when the caller chooses to supply the input is a check the
 * caller can decline: a signed request with the hash omitted and a download_url pointing anywhere
 * installed and activated an arbitrary zip.
 *
 * These assert the refusal happens BEFORE anything is downloaded, which is why no HTTP stub is
 * needed to prove it.
 */
final class PackageIntegrityRequiredTest extends TestCase
{
    /** A syntactically valid sha256, so only its presence is under test. */
    private const HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        require_once __DIR__.'/../../Fakes/wordpress-connector-stubs.php';

        if (! defined('ABSPATH')) {
            define('ABSPATH', sys_get_temp_dir().'/');
        }
        if (! defined('SAM_REST_NAMESPACE')) {
            define('SAM_REST_NAMESPACE', 'simplead/v1');
        }

        $plugin = __DIR__.'/../../../wordpress-plugin/simplead-manager-connector/includes/';
        require_once $plugin.'class-rest-api.php';
        require_once $plugin.'endpoints/class-package-install-endpoint.php';
    }

    public function test_it_refuses_a_package_with_no_hash(): void
    {
        $response = $this->install([
            'slug' => 'simplead-backup',
            'download_url' => 'https://manager.example.com/download/backup.zip',
        ]);

        $this->assertSame(400, $response->get_status());
        $this->assertSame('HASH_REQUIRED', $response->get_data()['error']['code']);
    }

    public function test_it_refuses_a_hash_that_is_not_a_sha256(): void
    {
        foreach (['nope', str_repeat('z', 64), substr(self::HASH, 0, 32)] as $bad) {
            $response = $this->install([
                'slug' => 'simplead-backup',
                'download_url' => 'https://manager.example.com/download/backup.zip',
                'expected_hash' => $bad,
            ]);

            $this->assertSame(400, $response->get_status(), "must refuse: {$bad}");
            $this->assertSame('HASH_REQUIRED', $response->get_data()['error']['code']);
        }
    }

    /**
     * FILTER_VALIDATE_URL alone accepts file:// and ftp://, which is not something "the manager
     * sent us a package" can ever mean.
     */
    public function test_it_refuses_a_download_url_that_is_not_http(): void
    {
        foreach (['file:///etc/passwd', 'ftp://example.com/x.zip'] as $url) {
            $response = $this->install([
                'slug' => 'simplead-backup',
                'download_url' => $url,
                'expected_hash' => self::HASH,
            ]);

            $this->assertSame(400, $response->get_status(), "must refuse: {$url}");
            $this->assertSame('INVALID_URL', $response->get_data()['error']['code']);
        }
    }

    /** The slug allowlist is unchanged and still comes first. */
    public function test_it_still_refuses_a_slug_it_does_not_own(): void
    {
        $response = $this->install([
            'slug' => 'akismet',
            'download_url' => 'https://manager.example.com/download/x.zip',
            'expected_hash' => self::HASH,
        ]);

        $this->assertSame(400, $response->get_status());
        $this->assertSame('SLUG_NOT_ALLOWED', $response->get_data()['error']['code']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function install(array $payload): \WP_REST_Response
    {
        $endpoint = new SAM_Package_Install_Endpoint;

        return $endpoint->install_package(new WP_REST_Request(
            'POST',
            '/simplead/v1/plugins/install-package',
            (string) json_encode($payload),
        ));
    }
}
