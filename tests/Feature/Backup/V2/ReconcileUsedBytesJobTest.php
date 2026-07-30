<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2;

use App\Backup\V2\Jobs\ReconcileUsedBytesJob;
use App\Models\StorageDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ReconcileUsedBytesJobTest extends TestCase
{
    use RefreshDatabase;

    private string $storageDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageDir = sys_get_temp_dir().'/usedbytes_'.uniqid();
        mkdir($this->storageDir, 0700, true);
    }

    protected function tearDown(): void
    {
        @array_map('unlink', glob($this->storageDir.'/*') ?: []);
        @rmdir($this->storageDir);
        parent::tearDown();
    }

    private function destination(int $usedBytes): StorageDestination
    {
        return StorageDestination::factory()->create([
            'type' => 'local',
            'config' => ['path' => $this->storageDir],
            'is_active' => true,
            'used_bytes' => $usedBytes,
        ]);
    }

    public function test_inert_when_engine_disabled(): void
    {
        Config::set('backup_v2.enabled', false);
        Config::set('backup_v2.reconciliation_writes_enabled', true);

        $dest = $this->destination(usedBytes: 123);
        file_put_contents($this->storageDir.'/a.bin', str_repeat('x', 500));

        (new ReconcileUsedBytesJob($dest->id))->handle();

        $dest->refresh();
        $this->assertSame(123, $dest->used_bytes, 'kill-switch must prevent any write');
    }

    public function test_read_only_when_writes_disabled(): void
    {
        Config::set('backup_v2.enabled', true);
        Config::set('backup_v2.reconciliation_writes_enabled', false);

        $dest = $this->destination(usedBytes: 123);
        file_put_contents($this->storageDir.'/a.bin', str_repeat('x', 500));

        (new ReconcileUsedBytesJob($dest->id))->handle();

        $dest->refresh();
        $this->assertSame(123, $dest->used_bytes, 'read-only mode must not persist the corrected value');
    }

    public function test_corrects_used_bytes_when_writes_enabled(): void
    {
        Config::set('backup_v2.enabled', true);
        Config::set('backup_v2.reconciliation_writes_enabled', true);

        $dest = $this->destination(usedBytes: 0);
        file_put_contents($this->storageDir.'/a.bin', str_repeat('x', 500));

        (new ReconcileUsedBytesJob($dest->id))->handle();

        $dest->refresh();
        $this->assertSame(500, $dest->used_bytes);
    }
}
