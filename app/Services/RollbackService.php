<?php

namespace App\Services;

use App\Jobs\SyncWordPressSite;
use App\Models\RollbackPoint;
use App\Models\Site;
use App\Models\UpdateLog;
use Illuminate\Support\Collection;

class RollbackService
{
    public function createRollbackPoint(Site $site, string $type, string $slug, string $fromVersion, string $toVersion): RollbackPoint
    {
        return RollbackPoint::create([
            'site_id' => $site->id,
            'type' => $type,
            'slug' => $slug,
            'from_version' => $fromVersion,
            'to_version' => $toVersion,
            'status' => 'available',
            'expires_at' => now()->addDays(30),
        ]);
    }

    public function executeRollback(RollbackPoint $point): array
    {
        $site = $point->site;
        $api = new WordPressApiService($site);

        $result = $api->rollback($point->type, $point->slug, $point->from_version);

        $point->update(['status' => 'used']);

        UpdateLog::create([
            'site_id' => $site->id,
            'user_id' => auth()->id(),
            'type' => $point->type,
            'name' => $point->slug,
            'slug' => $point->slug,
            'from_version' => $point->to_version,
            'to_version' => $point->from_version,
            'success' => $result['success'] ?? true,
            'error_message' => $result['error'] ?? null,
            'performed_at' => now(),
        ]);

        SyncWordPressSite::dispatch($site);

        return $result;
    }

    public function cleanExpired(): int
    {
        return RollbackPoint::where('status', 'available')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);
    }

    public function getAvailablePoints(Site $site): Collection
    {
        return $site->rollbackPoints()
            ->available()
            ->orderByDesc('created_at')
            ->get();
    }
}
