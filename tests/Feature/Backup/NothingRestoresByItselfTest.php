<?php

declare(strict_types=1);

namespace Tests\Feature\Backup;

use App\Enums\BackupStatus;
use App\Jobs\RunProvenRestore;
use App\Models\Backup;
use App\Models\ProvenRestore;
use App\Models\Site;
use App\Services\Backup\BackupV2Settings;
use App\Services\Backup\SandboxRestoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

/**
 * Nothing restores a site unless a person asks for it.
 *
 * One scheduled job in this product restores anything at all: the weekly proven restore, which puts
 * a site's latest backup into a throwaway sandbox and health-checks the result. It was already
 * inert — no site carried the per-site flag it looks for — but "inert because a list happens to be
 * empty" is not the same as "off", and this is the single operation here that overwrites a live
 * WordPress.
 *
 * So it is a switch, off by default, and the service refuses a non-sandbox target on its own rather
 * than trusting the one caller that selects correctly.
 */
#[Group('backup-v2')]
class NothingRestoresByItselfTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_weekly_proven_restore_does_nothing_while_the_switch_is_off(): void
    {
        $this->assertFalse(
            app(BackupV2Settings::class)->automaticRestoresEnabled(),
            'automatic restores must be off unless somebody has said otherwise',
        );

        $sandbox = Site::factory()->create(['is_sandbox' => true]);
        $source = Site::factory()->create(['proven_restore_enabled' => true, 'is_sandbox' => false]);
        Backup::factory()->create([
            'site_id' => $source->id,
            'status' => BackupStatus::Completed,
            'format' => 'v3-zip',
        ]);

        // A service that would notice if it were ever called.
        $this->app->instance(SandboxRestoreService::class, new class extends SandboxRestoreService
        {
            public function __construct() {}

            public function prove(Site $sandbox, Backup $backup): array
            {
                throw new RuntimeException('a restore ran without anyone asking for it');
            }
        });

        (new RunProvenRestore)->handle(app(SandboxRestoreService::class));

        // Nothing ran, and nothing was recorded as having run.
        $this->assertSame(0, ProvenRestore::count());
        $this->assertNotNull($sandbox->fresh(), 'the sandbox is untouched');
    }

    public function test_the_sandbox_service_refuses_a_live_site_on_its_own(): void
    {
        // The only thing keeping a client's site out of this was that its single caller happened to
        // select the sandbox first. One careless call away from restoring somebody else's backup
        // over a live site — so the refusal belongs here, not in the caller.
        $live = Site::factory()->create(['is_sandbox' => false]);
        $backup = Backup::factory()->create(['status' => BackupStatus::Completed, 'format' => 'v3-zip']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/only target the sandbox/');

        app(SandboxRestoreService::class)->restoreInto($live, $backup);
    }

    public function test_turning_it_on_is_a_deliberate_act_that_can_be_seen(): void
    {
        $settings = app(BackupV2Settings::class);

        $settings->setAutomaticRestoresEnabled(true);
        $this->assertTrue(app(BackupV2Settings::class)->automaticRestoresEnabled());

        $settings->setAutomaticRestoresEnabled(false);
        $this->assertFalse(app(BackupV2Settings::class)->automaticRestoresEnabled());
    }
}
