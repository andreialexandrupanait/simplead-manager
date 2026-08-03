<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Plugin;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * The connector's REST hardening has to know about BOTH of the manager's plugins.
 *
 * It knew about one. `restrict_rest_api` blocks unauthenticated REST access and waves through
 * `simplead/v1` — the connector's own namespace — while the backup engine lives at
 * `simplead-backup/v1`. So on every site with that hardening on, the engine was installed, running,
 * and answering 401 `rest_not_logged_in` to the manager that had just installed it.
 *
 * Found the day the fleet moved: 13 of 29 sites, all with the same error, all of them ours.
 *
 * Runs in its own process — the connector's files define constants and expect ABSPATH, and neither
 * should leak into the rest of the suite.
 */
#[Group('backup-v2')]
#[RunTestsInSeparateProcesses]
class RestHardeningNamespaceTest extends TestCase
{
    private string $muDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->muDir = sys_get_temp_dir().'/sam_mu_'.uniqid('', true);
        @mkdir($this->muDir, 0755, true);

        require_once __DIR__.'/../Support/wordpress-stubs.php';

        if (! defined('ABSPATH')) {
            define('ABSPATH', $this->muDir.'/');
        }
        if (! defined('WPMU_PLUGIN_DIR')) {
            define('WPMU_PLUGIN_DIR', $this->muDir);
        }
        if (! defined('SAM_VERSION')) {
            define('SAM_VERSION', '2.26.0');
        }

        require_once base_path('wordpress-plugin/simplead-manager-connector/includes/class-mu-plugin-manager.php');
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->muDir.'/*') as $f) {
            @unlink((string) $f);
        }
        @rmdir($this->muDir);
        parent::tearDown();
    }

    public function test_the_generated_mu_plugin_lets_the_backup_engine_through(): void
    {
        $result = \SAM_MU_Plugin_Manager::install();

        $this->assertSame('installed', $result['result']);

        $written = (string) file_get_contents($this->muDir.'/simplead-security.php');

        // The namespaces are named, and the '/wp-json' prefix is composed around them at runtime —
        // because matching the raw REQUEST_URI (query string included) let anyone claim to be us
        // by appending our namespace to a query parameter.
        $this->assertStringContainsString(
            '/simplead/v1',
            $written,
            'the connector still has to be reachable',
        );
        $this->assertStringContainsString(
            '/simplead-backup/v1',
            $written,
            'and so does the engine that takes the backups — this is the line whose absence made a '
            .'freshly installed engine answer 401 to us',
        );
        $this->assertStringContainsString(
            'parse_url($uri, PHP_URL_PATH)',
            $written,
            'and it must match the path, not the whole URI',
        );
    }

    /**
     * The connector enforces the same rule itself when it is active, and that copy had the same
     * blind spot. Fixing only the MU-plugin would leave the engine blocked on exactly the sites
     * where the connector is running — which is all of them.
     */
    public function test_the_connectors_own_filter_lets_the_backup_engine_through(): void
    {
        require_once base_path('wordpress-plugin/simplead-manager-connector/includes/class-security-hardening.php');

        $hardening = new \SAM_Security_Hardening;

        $_SERVER['REQUEST_URI'] = '/wp-json/simplead-backup/v1/capabilities';
        $this->assertNull(
            $hardening->restrict_rest_api(null),
            'the backup engine must not be turned away by our own hardening',
        );

        $_SERVER['REQUEST_URI'] = '/wp-json/simplead/v1/health';
        $this->assertNull($hardening->restrict_rest_api(null), 'nor the connector');

        // ...while everything else still is. The restriction itself was never the problem.
        $_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/users';
        $this->assertInstanceOf(\WP_Error::class, $hardening->restrict_rest_api(null));
    }

    /**
     * The exemption was decided by looking for our namespace anywhere in REQUEST_URI — query
     * string included. So appending `?x=/wp-json/simplead/v1/` to any request claimed to be us and
     * unlocked every REST endpoint this filter exists to keep unauthenticated callers out of.
     */
    public function test_the_namespace_cannot_be_smuggled_in_through_the_query_string(): void
    {
        require_once base_path('wordpress-plugin/simplead-manager-connector/includes/class-security-hardening.php');

        $hardening = new \SAM_Security_Hardening;

        foreach ([
            '/wp-json/wp/v2/users?x=/wp-json/simplead/v1/',
            '/wp-json/wp/v2/users?x=/simplead-backup/v1/',
            '/?p=1&redirect=/wp-json/simplead/v1/health',
        ] as $uri) {
            $_SERVER['REQUEST_URI'] = $uri;
            unset($_GET['rest_route']);

            $this->assertInstanceOf(
                \WP_Error::class,
                $hardening->restrict_rest_api(null),
                "a query string must not exempt a request: {$uri}",
            );
        }
    }

    /**
     * On a site with plain permalinks the namespace legitimately lives in the query string, as the
     * value of rest_route — so the fix above must not lock our own calls out.
     */
    public function test_plain_permalink_requests_to_our_own_namespaces_still_pass(): void
    {
        require_once base_path('wordpress-plugin/simplead-manager-connector/includes/class-security-hardening.php');

        $hardening = new \SAM_Security_Hardening;
        $_SERVER['REQUEST_URI'] = '/index.php?rest_route=/simplead-backup/v1/capabilities';

        $_GET['rest_route'] = '/simplead-backup/v1/capabilities';
        $this->assertNull($hardening->restrict_rest_api(null));

        $_GET['rest_route'] = '/wp/v2/users';
        $this->assertInstanceOf(\WP_Error::class, $hardening->restrict_rest_api(null));

        unset($_GET['rest_route']);
    }

    /**
     * The MU-plugin is rewritten on every connector self-update, which is what carries this fix to
     * the sites it is broken on. A generator that could not overwrite would leave them stuck.
     */
    public function test_installing_over_an_older_file_replaces_it(): void
    {
        file_put_contents($this->muDir.'/simplead-security.php', "<?php // an older generation\n");

        \SAM_MU_Plugin_Manager::install();

        $written = (string) file_get_contents($this->muDir.'/simplead-security.php');

        $this->assertStringNotContainsString('an older generation', $written);
        $this->assertStringContainsString('/simplead-backup/v1', $written);
    }
}
