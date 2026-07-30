<?php

declare(strict_types=1);

namespace Tests\Feature\Connector;

use Tests\TestCase;

/**
 * E2E Bug 2 regression — the connector's plugin ROLLBACK must reinstall the exact
 * pinned version, and fail explicitly when that version is gone from wp.org.
 *
 * Like the Bug 1 fix, this asserts against the REAL external contract (live
 * wordpress.org), not an invented mock: the pinned-version download that used to
 * be silently swapped for "latest" is exactly what these tests pin down. It loads
 * the connector's pure resolver directly (no WordPress runtime needed).
 *
 * @group network
 */
class PluginRollbackVersionContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once base_path(
            'wordpress-plugin/simplead-manager-connector/includes/support/class-rollback-version.php'
        );
    }

    public function test_download_url_is_the_exact_versioned_wp_org_zip(): void
    {
        $this->assertSame(
            'https://downloads.wordpress.org/plugin/classic-editor.1.6.zip',
            \SAM_Rollback_Version::plugin_download_url('classic-editor', '1.6'),
        );
    }

    public function test_an_existing_old_version_is_available_on_real_wp_org(): void
    {
        $url = \SAM_Rollback_Version::plugin_download_url('classic-editor', '1.6');

        // Real wp.org contract: an old-but-hosted version HEADs 200.
        if (\SAM_Rollback_Version::default_head($url) === 0) {
            $this->markTestSkipped('wordpress.org unreachable — network-gated contract test.');
        }

        $this->assertTrue(
            \SAM_Rollback_Version::remote_version_available($url),
            'A still-hosted pinned version must be reported available so the rollback proceeds to it.',
        );
    }

    public function test_a_removed_version_is_unavailable_so_rollback_fails_explicitly(): void
    {
        // Prove wp.org is reachable via a known-good URL before asserting a 404.
        if (\SAM_Rollback_Version::default_head(\SAM_Rollback_Version::plugin_download_url('classic-editor', '1.6')) === 0) {
            $this->markTestSkipped('wordpress.org unreachable — network-gated contract test.');
        }

        $missing = \SAM_Rollback_Version::plugin_download_url('classic-editor', '999.999.999');

        // The exact behaviour Bug 2 requires: a version wp.org no longer serves is
        // NOT available → the endpoint returns VERSION_UNAVAILABLE instead of
        // installing whatever is latest.
        $this->assertFalse(
            \SAM_Rollback_Version::remote_version_available($missing),
            'A version wp.org no longer hosts must be reported unavailable so the rollback refuses to install a different one.',
        );
    }

    public function test_theme_download_url_is_the_exact_versioned_wp_org_zip(): void
    {
        $this->assertSame(
            'https://downloads.wordpress.org/theme/twentytwenty.1.9.zip',
            \SAM_Rollback_Version::theme_download_url('twentytwenty', '1.9'),
        );
    }

    public function test_an_existing_old_theme_version_is_available_on_real_wp_org(): void
    {
        $url = \SAM_Rollback_Version::theme_download_url('twentytwenty', '1.9');

        // Real wp.org contract: an old-but-hosted theme version HEADs 200.
        if (\SAM_Rollback_Version::default_head($url) === 0) {
            $this->markTestSkipped('wordpress.org unreachable — network-gated contract test.');
        }

        $this->assertTrue(
            \SAM_Rollback_Version::remote_version_available($url),
            'A still-hosted pinned theme version must be reported available so the rollback proceeds to it.',
        );
    }

    public function test_a_removed_theme_version_is_unavailable_so_rollback_fails_explicitly(): void
    {
        // Prove wp.org is reachable via a known-good URL before asserting a 404.
        if (\SAM_Rollback_Version::default_head(\SAM_Rollback_Version::theme_download_url('twentytwenty', '1.9')) === 0) {
            $this->markTestSkipped('wordpress.org unreachable — network-gated contract test.');
        }

        $missing = \SAM_Rollback_Version::theme_download_url('twentytwenty', '999.999.999');

        // Same guarantee as plugins: a theme version wp.org no longer serves is
        // NOT available → the endpoint returns VERSION_UNAVAILABLE instead of
        // installing whatever is latest.
        $this->assertFalse(
            \SAM_Rollback_Version::remote_version_available($missing),
            'A theme version wp.org no longer hosts must be reported unavailable so the rollback refuses to install a different one.',
        );
    }

    public function test_availability_decision_is_2xx_only(): void
    {
        // Pure boundary check (no network): 2xx = available, anything else = not.
        $url = \SAM_Rollback_Version::plugin_download_url('x', '1.0');

        $this->assertTrue(\SAM_Rollback_Version::remote_version_available($url, fn () => 200));
        $this->assertTrue(\SAM_Rollback_Version::remote_version_available($url, fn () => 206));
        $this->assertFalse(\SAM_Rollback_Version::remote_version_available($url, fn () => 404));
        $this->assertFalse(\SAM_Rollback_Version::remote_version_available($url, fn () => 302));
        $this->assertFalse(\SAM_Rollback_Version::remote_version_available($url, fn () => 0));
    }
}
