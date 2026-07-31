<?php

declare(strict_types=1);

namespace Tests\Feature\Backup;

use App\Enums\BackupEngine;
use App\Models\Backup;
use App\Models\BackupConfig;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Nothing moves engine on its own.
 *
 * The column is about to gate destructive behaviour — retention's delete branch,
 * stuck recovery, restore-verification. Every one of those reads it as "is this
 * row's storage layout the old one", and every one of them is destructive or
 * disruptive if the answer is wrong. A row that somehow arrived without a value,
 * or a site that silently ended up on the new engine, would be the worst kind of
 * bug: invisible until a backup is deleted or two engines run on one site.
 *
 * So: default v1, everywhere, for both tables, and no code path in this build
 * writes anything else.
 */
class EngineColumnTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_backup_is_v1_by_default(): void
    {
        $backup = Backup::factory()->create();

        $this->assertSame(BackupEngine::V1, $backup->fresh()->engine);
    }

    public function test_a_new_backup_config_is_v1_by_default(): void
    {
        $config = BackupConfig::factory()->create(['site_id' => Site::factory()->create()->id]);

        $this->assertSame(BackupEngine::V1, $config->fresh()->backup_engine);
    }

    /**
     * The column is NOT NULL with a default, so a row inserted by anything that
     * does not know about it — a raw query, a factory, an older code path — still
     * gets a truthful value rather than null.
     */
    public function test_a_raw_insert_that_ignores_the_column_still_gets_v1(): void
    {
        $site = Site::factory()->create();

        DB::table('backups')->insert([
            'site_id' => $site->id,
            'type' => 'full',
            'status' => 'pending',
            'trigger' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame('v1', DB::table('backups')->where('site_id', $site->id)->value('engine'));
    }

    /**
     * The proof the plan asks for before the branching lands: every existing row
     * is v1, so adding `where('engine', 'v1')` to a query that currently selects
     * everything cannot start selecting nothing.
     */
    public function test_every_backup_in_the_table_is_v1(): void
    {
        Backup::factory()->count(3)->create();

        $engines = DB::table('backups')->select('engine')->distinct()->pluck('engine')->all();

        $this->assertSame(['v1'], $engines);
    }
}
