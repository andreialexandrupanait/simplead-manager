<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SyncWordPressSite;
use App\Models\RollbackPoint;
use App\Models\Site;
use App\Models\UpdateLog;
use Illuminate\Support\Collection;

class RollbackService
{
    public function __construct(
        protected WordPressApiServiceFactory $apiFactory,
    ) {}

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

    public function executeRollback(RollbackPoint $point, ?int $userId = null): array
    {
        /** @var Site $site */
        $site = $point->site;
        $api = $this->apiFactory->make($site);

        $result = $api->rollback($point->type, $point->slug, $point->from_version);

        // P2-28: validate the connector genuinely rolled back BEFORE consuming the
        // point or recording success. Previously the point was marked "used" and
        // the log defaulted to success whenever the payload lacked an explicit
        // success flag — so a failed/empty rollback still burned the only rollback
        // point and was recorded as a success, leaving the site stuck on the broken
        // version with nothing to fall back to. Only a payload that explicitly
        // reports success is treated as success.
        $succeeded = ($result['success'] ?? null) === true;

        if ($succeeded) {
            $point->update(['status' => 'used']);
        }

        UpdateLog::create([
            'site_id' => $site->id,
            'user_id' => $userId ?? auth()->id(),
            'type' => $point->type,
            'name' => $point->slug,
            'slug' => $point->slug,
            'from_version' => $point->to_version,
            'to_version' => $point->from_version,
            'success' => $succeeded,
            'error_message' => $this->stringifyError($result['error'] ?? null),
            'performed_at' => now(),
        ]);

        SyncWordPressSite::dispatch($site);

        return $result;
    }

    /**
     * The connector reports a rollback error as a string, but a transport-level
     * failure can surface it as an array; normalise both to a stored message so a
     * failed rollback never breaks on the text `error_message` column.
     */
    private function stringifyError(mixed $error): ?string
    {
        if ($error === null || $error === '') {
            return null;
        }

        if (is_string($error)) {
            return $error;
        }

        $encoded = json_encode($error);

        return $encoded === false ? null : $encoded;
    }

    public function cleanExpired(): int
    {
        return RollbackPoint::where('status', 'available')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);
    }

    public function getAvailablePoints(Site $site): Collection
    {
        return RollbackPoint::where('site_id', $site->id)
            ->available()
            ->orderByDesc('created_at')
            ->get();
    }
}
