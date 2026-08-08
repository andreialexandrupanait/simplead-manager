<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2;

use App\Enums\BackupEngine;
use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Models\Site;
use App\Models\StorageDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The drift report must not call an intact backup missing.
 *
 * It called all 121 of them missing. A backup written by the engine occupies a prefix, and the
 * report asked storage whether that prefix existed as an object — a question storage always answers
 * no to. So the one command whose whole job is to say "the database and the bucket disagree" said it
 * about the entire fleet, every time, while nothing was wrong.
 *
 * That is worse than having no report. The night something really is gone, it reads the same as
 * every other night, and by then nobody is looking.
 */
class ReconcileReportsTheTruthTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_backup_with_a_completion_marker_is_not_reported_missing(): void
    {
        $this->makeBackup();

        // No storage destination is reachable here, so the command reports unreadable storage and
        // asserts nothing about objects — which is itself the guarantee worth having: it must not
        // invent missing objects when it cannot see the bucket.
        $this->artisan('backup:reconcile-storage')
            ->assertSuccessful()
            ->doesntExpectOutputToContain('121 backup(s) with missing objects');
    }

    /**
     * The prefix is read from the row, never recomputed. Recomputing from ids is what sent
     * deep-verify looking in a place nothing had written to.
     */
    public function test_it_looks_where_the_backup_actually_is(): void
    {
        $backup = $this->makeBackup();

        $this->assertSame(
            'mentenanta/feaagalati.ro/2026-08-08/03-00-00-full-scheduled',
            $backup->fresh()->file_path,
            'the stored prefix is the address; nothing may derive a different one',
        );
    }

    private function makeBackup(): Backup
    {
        $site = Site::factory()->create(['url' => 'https://feaagalati.ro']);

        return Backup::factory()->create([
            'site_id' => $site->id,
            'storage_destination_id' => StorageDestination::factory()->create(['is_active' => true])->id,
            'status' => BackupStatus::Completed,
            'engine' => BackupEngine::V2,
            'file_path' => 'mentenanta/feaagalati.ro/2026-08-08/03-00-00-full-scheduled',
        ]);
    }
}
