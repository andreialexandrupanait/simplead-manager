<?php

declare(strict_types=1);

namespace Tests\Unit\Backup\V2\Storage;

use App\Backup\V2\Storage\InMemoryProgressStore;
use App\Backup\V2\Storage\ObjectLayout;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ObjectLayoutTest extends TestCase
{
    public function test_builds_tenant_isolated_keys_from_configured_prefix(): void
    {
        Config::set('backup_v2.object_prefix', 'clients/{client_id}/sites/{site_id}/backups/{backup_id}');

        $layout = ObjectLayout::forBackup(clientId: 7, siteId: 42, backupId: 1001);

        $this->assertSame('clients/7/sites/42/backups/1001', $layout->prefix());
        $this->assertSame('clients/7/sites/42/backups/1001/', $layout->listPrefix());
        $this->assertSame('clients/7/sites/42/backups/1001/database/dump-001.sql.gz', $layout->database('dump-001.sql.gz'));
        $this->assertSame('clients/7/sites/42/backups/1001/files/chunk-0007.tar', $layout->files('chunk-0007.tar'));
        $this->assertSame('clients/7/sites/42/backups/1001/manifest.json', $layout->manifest());
        $this->assertSame('clients/7/sites/42/backups/1001/checksums.json', $layout->checksums());
        $this->assertSame('clients/7/sites/42/backups/1001/metadata.json', $layout->metadata());
        $this->assertSame('clients/7/sites/42/backups/1001/restore.json', $layout->restore());
        $this->assertSame('clients/7/sites/42/backups/1001/_COMPLETE', $layout->completeMarker());
    }

    public function test_directory_helpers_without_argument_return_the_folder_prefix(): void
    {
        $layout = new ObjectLayout(1, 2, 3);

        $this->assertSame('clients/1/sites/2/backups/3/database/', $layout->database());
        $this->assertSame('clients/1/sites/2/backups/3/files/', $layout->files());
    }

    public function test_key_normalises_leading_slashes(): void
    {
        $layout = new ObjectLayout(1, 2, 3);

        $this->assertSame('clients/1/sites/2/backups/3/logs/run.log', $layout->key('/logs/run.log'));
    }

    public function test_in_memory_progress_store_round_trips_and_dedupes_parts(): void
    {
        $store = new InMemoryProgressStore;
        $key = 'clients/1/sites/2/backups/3/files/chunk';

        $this->assertNull($store->getUploadId($key));
        $this->assertSame([], $store->confirmedParts($key));

        $store->putUploadId($key, 'UPLOAD-XYZ');
        $this->assertSame('UPLOAD-XYZ', $store->getUploadId($key));

        $store->confirmPart($key, ['part_number' => 2, 'etag' => 'e2', 'sha256' => 's2', 'size' => 20]);
        $store->confirmPart($key, ['part_number' => 1, 'etag' => 'e1', 'sha256' => 's1', 'size' => 10]);
        // Idempotent replace on the same part_number.
        $store->confirmPart($key, ['part_number' => 1, 'etag' => 'e1b', 'sha256' => 's1b', 'size' => 11]);

        $parts = $store->confirmedParts($key);
        $this->assertCount(2, $parts);
        $this->assertSame(1, $parts[0]['part_number'], 'parts sorted ascending');
        $this->assertSame('e1b', $parts[0]['etag'], 'same part_number replaced, not duplicated');
        $this->assertSame(2, $parts[1]['part_number']);

        $store->clear($key);
        $this->assertNull($store->getUploadId($key));
        $this->assertSame([], $store->confirmedParts($key));
    }
}
