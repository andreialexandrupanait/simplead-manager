<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Support;

use App\Backup\V2\Support\BackupV2Gate;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * BackupV2Gate::allowsSite — the engine-run gate (enabled AND on the allowlist),
 * fail-closed on an empty allowlist.
 */
class BackupV2GateTest extends TestCase
{
    public function test_enabled_and_allowlisted_site_is_allowed(): void
    {
        Config::set('backup_v2.enabled', true);
        Config::set('backup_v2.site_ids', ['5', '9']);

        $this->assertTrue(BackupV2Gate::allowsSite(5));
        $this->assertTrue(BackupV2Gate::allowsSite(9));
    }

    public function test_enabled_but_not_allowlisted_site_is_refused(): void
    {
        Config::set('backup_v2.enabled', true);
        Config::set('backup_v2.site_ids', ['5']);

        $this->assertFalse(BackupV2Gate::allowsSite(6));
    }

    public function test_disabled_engine_refuses_even_an_allowlisted_site(): void
    {
        Config::set('backup_v2.enabled', false);
        Config::set('backup_v2.site_ids', ['5']);

        $this->assertFalse(BackupV2Gate::allowsSite(5));
    }

    public function test_empty_allowlist_refuses_every_site_even_when_enabled(): void
    {
        Config::set('backup_v2.enabled', true);
        Config::set('backup_v2.site_ids', []);

        $this->assertFalse(BackupV2Gate::allowsSite(5));
        $this->assertFalse(BackupV2Gate::siteAllowed(5));
    }
}
