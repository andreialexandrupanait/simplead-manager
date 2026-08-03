<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Plugin;

use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Nothing the backup engine returns may be cacheable, and its own loopback probe must not create
 * a cacheable response either.
 *
 * Found on the first client canary. The probe added in 0.9.0 asked the site for its own
 * `/capabilities` over **GET** — the manager has always used POST — and WordPress put no
 * `Cache-Control` on the reply. Behind Cloudflare that single cacheable 200 was stored and then
 * served to anyone who asked, unsigned: `cf-cache-status: HIT` on a payload carrying the absolute
 * webroot path, the database name and the PHP and MariaDB versions. Sites still on 0.8.2 answered
 * 401 to the same request, which is how it was pinned to the probe rather than to the endpoint.
 *
 * Two independent guards, because either alone is one CDN default away from failing again:
 * every response in the namespace declares itself uncacheable, and the probe uses a method no
 * cache will store.
 */
#[Group('backup-v2')]
class BackupResponsesAreNeverCacheableTest extends TestCase
{
    public function test_the_namespace_declares_every_response_uncacheable(): void
    {
        $source = (string) file_get_contents(
            base_path('wordpress-plugin/simplead-backup/includes/class-backup-plugin.php')
        );

        $this->assertStringContainsString(
            "add_filter('rest_post_dispatch'",
            $source,
            'the no-store headers have to be applied to the whole namespace, not per endpoint',
        );
        $this->assertStringContainsString('no-store', $source);

        // Heuristic freshness is what a CDN falls back to when Cache-Control is absent, and
        // Last-Modified is what it measures that from.
        $this->assertStringContainsString("'Last-Modified', ''", $source);
    }

    public function test_the_loopback_probe_does_not_use_a_cacheable_method(): void
    {
        $source = (string) file_get_contents(
            base_path('wordpress-plugin/simplead-backup/includes/support/class-loopback.php')
        );

        $this->assertStringNotContainsString(
            'wp_remote_get(',
            $source,
            'a GET probe is what put this endpoint in a CDN cache',
        );
        $this->assertStringContainsString('wp_remote_post(', $source);

        // The signed string has to name the method actually used, or the probe 401s and the
        // engine reports that it cannot detach.
        $this->assertMatchesRegularExpression(
            "/implode\(\s*'\|'\s*,\s*array\(\s*'POST'/",
            $source,
            'the signature must be built over POST',
        );
    }
}
