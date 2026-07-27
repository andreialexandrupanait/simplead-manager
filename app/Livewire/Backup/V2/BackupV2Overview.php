<?php

declare(strict_types=1);

namespace App\Livewire\Backup\V2;

use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Enums\RestoreSessionState;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Models\RestoreSession;
use App\Backup\V2\Quota\QuotaService;
use App\Backup\V2\Support\BackupV2Access;
use App\Models\StorageDestination;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * V2 backup console — GLOBAL overview (P5). Isolated, admin-only, flag-gated
 * component that renders NOTHING of value when the console is disabled, so it is
 * invisible in production with the default flags.
 *
 * Reads only from the V2 tables (backup_sessions / restore_sessions) + the
 * reconciled StorageDestination.used_bytes. It never touches the V1 backups table
 * or the V1 UI.
 */
class BackupV2Overview extends Component
{
    /** A session that has not heartbeat within this window is "stale". */
    private const STALE_HEARTBEAT_SECONDS = 900;

    public bool $enabled = false;

    public function mount(): void
    {
        $this->enabled = BackupV2Access::allows(auth()->user());
    }

    /**
     * @return array{active:int,failed:int,paused:int,stale:int,completed:int,incomplete:int,proven_restore:int}
     */
    #[Computed]
    public function stats(): array
    {
        if (! $this->enabled) {
            return ['active' => 0, 'failed' => 0, 'paused' => 0, 'stale' => 0, 'completed' => 0, 'incomplete' => 0, 'proven_restore' => 0];
        }

        return [
            'active' => BackupSession::query()->whereNotIn('state', $this->terminalStates())->count(),
            'failed' => BackupSession::query()->whereIn('state', [BackupSessionState::Failed->value, BackupSessionState::Corrupt->value])->count(),
            'paused' => BackupSession::query()->where('state', BackupSessionState::Paused->value)->count(),
            'stale' => $this->staleSessions()->count(),
            'completed' => BackupSession::query()->where('state', BackupSessionState::Completed->value)->count(),
            'incomplete' => $this->incompleteSessions()->count(),
            'proven_restore' => RestoreSession::query()->where('state', RestoreSessionState::Completed->value)->count(),
        ];
    }

    /**
     * Active (in-flight) sessions.
     *
     * @return \Illuminate\Support\Collection<int, BackupSession>
     */
    #[Computed]
    public function activeSessions()
    {
        if (! $this->enabled) {
            return collect();
        }

        return BackupSession::with('site')
            ->whereNotIn('state', $this->terminalStates())
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    /**
     * Failed / corrupt sessions needing triage.
     *
     * @return \Illuminate\Support\Collection<int, BackupSession>
     */
    #[Computed]
    public function failedSessions()
    {
        if (! $this->enabled) {
            return collect();
        }

        return BackupSession::with('site')
            ->whereIn('state', [BackupSessionState::Failed->value, BackupSessionState::Corrupt->value])
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    /**
     * Stale sessions — active but heartbeat has gone quiet.
     *
     * @return \Illuminate\Support\Collection<int, BackupSession>
     */
    #[Computed]
    public function staleSessions()
    {
        if (! $this->enabled) {
            return collect();
        }

        return BackupSession::with('site')
            ->whereNotIn('state', $this->terminalStates())
            ->where(function ($q) {
                $q->whereNull('heartbeat_at')
                    ->orWhere('heartbeat_at', '<', now()->subSeconds(self::STALE_HEARTBEAT_SECONDS));
            })
            ->orderBy('heartbeat_at')
            ->limit(50)
            ->get();
    }

    /**
     * Incomplete backups — corrupt, or active-but-stale-with-partial-objects — that
     * left objects in storage without a valid completion marker.
     *
     * @return \Illuminate\Support\Collection<int, BackupSession>
     */
    #[Computed]
    public function incompleteSessions()
    {
        if (! $this->enabled) {
            return collect();
        }

        return BackupSession::with('site')
            ->where('state', BackupSessionState::Corrupt->value)
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    /**
     * Storage destinations with reconciled usage + quota state.
     *
     * @return \Illuminate\Support\Collection<int, mixed>
     */
    #[Computed]
    public function destinations()
    {
        if (! $this->enabled) {
            return collect();
        }

        $quota = app(QuotaService::class);

        return StorageDestination::query()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (StorageDestination $d): array => [
                'model' => $d,
                'used_bytes' => $quota->usedBytes($d),
                'quota_bytes' => $quota->quotaBytes($d),
                'percent' => $quota->usagePercent($d),
                'over_warn' => $quota->isOverWarnThreshold($d),
                'healthy' => $d->is_active && ($d->last_test_passed !== false),
            ]);
    }

    /**
     * Latest restores (proven-restore + user restores).
     *
     * @return \Illuminate\Support\Collection<int, RestoreSession>
     */
    #[Computed]
    public function latestRestores()
    {
        if (! $this->enabled) {
            return collect();
        }

        return RestoreSession::with('site')
            ->orderByDesc('id')
            ->limit(20)
            ->get();
    }

    /**
     * Derived alerts — failed sessions, stale sessions, destinations over the
     * warning threshold, and incomplete/orphaned backups.
     *
     * @return list<array{level:string,message:string}>
     */
    #[Computed]
    public function alerts(): array
    {
        if (! $this->enabled) {
            return [];
        }

        $alerts = [];
        $stats = $this->stats;

        if ($stats['failed'] > 0) {
            $alerts[] = ['level' => 'critical', 'message' => "{$stats['failed']} backup session(s) failed or corrupt."];
        }
        if ($stats['stale'] > 0) {
            $alerts[] = ['level' => 'warning', 'message' => "{$stats['stale']} session(s) are stale (no recent heartbeat)."];
        }
        if ($stats['incomplete'] > 0) {
            $alerts[] = ['level' => 'warning', 'message' => "{$stats['incomplete']} incomplete backup(s) may have left orphan objects in storage."];
        }
        foreach ($this->destinations as $d) {
            if ($d['over_warn']) {
                $alerts[] = ['level' => 'warning', 'message' => "Storage \"{$d['model']->name}\" is at {$d['percent']}% of its quota."];
            }
        }

        return $alerts;
    }

    /**
     * @return list<string>
     */
    private function terminalStates(): array
    {
        return [
            BackupSessionState::Completed->value,
            BackupSessionState::Cancelled->value,
            BackupSessionState::Failed->value,
            BackupSessionState::Corrupt->value,
        ];
    }

    public function render()
    {
        return view('livewire.backup.v2.backup-v2-overview')
            ->layout('components.layouts.app', ['title' => 'Backup V2']);
    }
}
