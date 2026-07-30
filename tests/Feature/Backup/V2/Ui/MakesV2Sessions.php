<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Ui;

use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Enums\RestoreSessionState;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Models\RestoreSession;
use App\Models\Site;
use Illuminate\Support\Str;

/**
 * Shared factory helpers for the V2 UI tests (BackupSession/RestoreSession have no
 * model factories).
 */
trait MakesV2Sessions
{
    protected function makeBackupSession(Site $site, array $overrides = []): BackupSession
    {
        return BackupSession::create(array_merge([
            'site_id' => $site->id,
            'type' => 'full',
            'scope' => ['database' => true, 'files' => true],
            'exclusions' => [],
            'resource_profile' => 'low_impact',
            'state' => BackupSessionState::Requested,
            'confirmed_objects' => [],
            'confirmed_parts' => [],
            'idempotency_key' => 'test-'.Str::random(20),
            'format_version' => 'simplead-backup/1',
        ], $overrides));
    }

    protected function makeRestoreSession(Site $site, array $overrides = []): RestoreSession
    {
        return RestoreSession::create(array_merge([
            'site_id' => $site->id,
            'mode' => 'safe_merge',
            'state' => RestoreSessionState::Requested,
            'idempotency_key' => 'test-restore-'.Str::random(20),
        ], $overrides));
    }
}
