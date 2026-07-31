<?php

declare(strict_types=1);

/**
 * Drive a REAL full BackupRunner from lab-php into a FIXED, non-cleaned MinIO
 * prefix ("db-oracle/...") and print the resulting DB segment object keys as JSON.
 * The companion bash oracle then pulls those segments and imports them into a fresh
 * MySQL database to prove a 0-error restore. Not a phpunit test (that tears MinIO
 * down); this leaves the objects in place for the import step.
 */
require '/work/vendor/autoload.php';
$app = require '/work/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Orchestration\BackupRunner;
use App\Backup\V2\Plugin\SimpleadBackupClient;
use App\Backup\V2\Storage\ObjectLayout;
use App\Backup\V2\Storage\S3ClientFactory;
use App\Models\Site;

$site = Site::factory()->create();
$session = BackupSession::create([
    'site_id' => $site->id,
    'type' => 'full',
    'scope' => [
        'database' => true,
        'files' => true,
        'rules' => [['type' => 'glob', 'value' => 'wp-content/uploads/sam-backup-lab/**', 'action' => 'include']],
        'exclude_tables' => [],
    ],
    'resource_profile' => 'low_impact',
    'state' => BackupSessionState::Requested,
    'confirmed_objects' => [],
    'confirmed_parts' => [],
    'checkpoint' => [],
    'idempotency_key' => 'db-oracle-'.uniqid('', true),
    'format_version' => 'simplead-backup/1',
]);

$factory = S3ClientFactory::lab();
$layout = new ObjectLayout(1, $session->site_id, $session->id, 'db-oracle/{client_id}/sites/{site_id}/backups/{backup_id}');

$runner = new BackupRunner(
    session: $session,
    client: SimpleadBackupClient::lab('http://sam_spike-spike-wp-1'),
    s3: $factory->client(),
    bucket: $factory->bucket(),
    layout: $layout,
    logger: null,
    fileChunkThreshold: 524288,
    dbSegmentBytes: 2097152,
);
$result = $runner->run();

if ($result->state !== BackupSessionState::Completed) {
    fwrite(STDERR, 'backup did not complete: '.$result->state->value."\n");
    exit(1);
}

// Read back the manifest for the DB object keys + source counts.
$tmp = tempnam(sys_get_temp_dir(), 'm_');
$factory->client()->getObject(['Bucket' => $factory->bucket(), 'Key' => $layout->manifest(), 'SaveAs' => $tmp]);
$manifest = json_decode((string) file_get_contents($tmp), true);
@unlink($tmp);

$dbKeys = [];
foreach ($manifest['objects'] as $o) {
    if ($o['kind'] === 'database') {
        $dbKeys[(int) $o['chunk_index']] = $o['key'];
    }
}
ksort($dbKeys);

echo json_encode([
    'bucket' => $factory->bucket(),
    'prefix' => $layout->listPrefix(),
    'db_keys' => array_values($dbKeys),
    'source_table_count' => $manifest['database']['table_count'],
    'source_total_rows' => $manifest['database']['total_rows'],
], JSON_PRETTY_PRINT).PHP_EOL;
