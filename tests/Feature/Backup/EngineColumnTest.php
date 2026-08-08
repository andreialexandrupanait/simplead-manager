<?php

declare(strict_types=1);

namespace Tests\Feature\Backup;

use App\Enums\BackupEngine;
use App\Models\Backup;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `backups.engine` labels which engine produced a row.
 *
 * It was introduced during the cutover, when there were two engines and the answer mattered for
 * dispatch. It no longer decides anything — one engine writes every backup — but it stays, because
 * it is a true statement about the rows already in the table and there is no gain in erasing it.
 *
 * What DID have to change is the default. A column that stamps new rows 'v1' names an engine that
 * has been deleted, and a mislabelled row is worse than an unlabelled one: `RetentionService` and
 * `BackupBrowserService` both branch on this value, and the v1 branch calls DeleteObject on what is
 * actually a prefix. S3 answers 204 for a key that does not exist, so the delete would report
 * success and orphan every object under that prefix, silently.
 *
 * The companion column `backup_configs.backup_engine` — the per-site roster saying which engine a
 * site WOULD use — was dropped rather than kept. That one recorded an intention about the future,
 * and there is no longer a future in which it means anything.
 */
class EngineColumnTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_backup_is_labelled_with_the_engine_that_exists(): void
    {
        $backup = Backup::factory()->create();

        $this->assertSame(BackupEngine::V2, $backup->fresh()->engine);
    }

    /**
     * The column is NOT NULL with a default, so a row inserted by anything that does not know about
     * it — a raw query, a factory, an older code path — still gets a truthful value rather than null
     * or a retired engine's name.
     */
    public function test_a_raw_insert_that_ignores_the_column_still_gets_a_truthful_label(): void
    {
        $site = Site::factory()->create();

        DB::table('backups')->insert([
            'site_id' => $site->id,
            'type' => 'full',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(
            BackupEngine::V2,
            Backup::query()->where('site_id', $site->id)->firstOrFail()->engine,
        );
    }

    /** The roster is gone from the schema, not merely unread. */
    public function test_the_per_site_engine_roster_no_longer_exists(): void
    {
        $this->assertFalse(
            DB::getSchemaBuilder()->hasColumn('backup_configs', 'backup_engine'),
            'backup_configs.backup_engine should have been dropped',
        );
    }
}
