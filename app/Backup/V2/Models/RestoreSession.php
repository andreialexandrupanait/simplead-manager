<?php

declare(strict_types=1);

namespace App\Backup\V2\Models;

use App\Backup\V2\Enums\BackupErrorCode;
use App\Backup\V2\Enums\RestoreSessionState;
use App\Backup\V2\StateMachine\RestoreStateMachine;
use App\Backup\V2\Support\BackupLogger;
use App\Models\Backup;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * V2 restore session — the explicit FSM row driving a single restore run.
 *
 * @property int $id
 * @property int $site_id
 * @property int|null $backup_session_id
 * @property int|null $source_backup_id
 * @property string $mode
 * @property array|null $scope
 * @property \App\Backup\V2\Enums\RestoreSessionState $state
 * @property string|null $stage
 * @property array|null $cursor
 * @property array|null $checkpoint
 * @property \Illuminate\Support\Carbon|null $heartbeat_at
 * @property int $attempt
 * @property int $retry_count
 * @property \Illuminate\Support\Carbon|null $next_retry_at
 * @property string $idempotency_key
 * @property string|null $error_code
 * @property string|null $error_message
 * @property array|null $rollback_point
 * @property int|null $pre_restore_backup_id
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 */
class RestoreSession extends Model
{
    protected $table = 'restore_sessions';

    protected $fillable = [
        'site_id',
        'backup_session_id',
        'source_backup_id',
        'mode',
        'scope',
        'state',
        'stage',
        'cursor',
        'checkpoint',
        'heartbeat_at',
        'attempt',
        'retry_count',
        'next_retry_at',
        'idempotency_key',
        'error_code',
        'error_message',
        'rollback_point',
        'pre_restore_backup_id',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'scope' => 'array',
        'cursor' => 'array',
        'checkpoint' => 'array',
        'rollback_point' => 'array',
        'state' => RestoreSessionState::class,
        'attempt' => 'integer',
        'retry_count' => 'integer',
        'heartbeat_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected $attributes = [
        'attempt' => 0,
        'retry_count' => 0,
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * The V2 backup session being restored (when the source is a V2 backup).
     */
    public function backupSession(): BelongsTo
    {
        return $this->belongsTo(BackupSession::class, 'backup_session_id');
    }

    /**
     * The V1 backups row being restored (legacy source).
     */
    public function sourceBackup(): BelongsTo
    {
        return $this->belongsTo(Backup::class, 'source_backup_id');
    }

    /**
     * The safety backup taken before this restore mutated the site.
     */
    public function preRestoreBackup(): BelongsTo
    {
        return $this->belongsTo(Backup::class, 'pre_restore_backup_id');
    }

    /**
     * Transition to a new FSM state through the state machine (idempotent on the
     * same state). Updates state + stage, writes a structured log line, and
     * saves. Illegal edges throw App\Backup\V2\Exceptions\IllegalStateTransition.
     */
    public function transitionTo(RestoreSessionState $to, ?string $stage = null): self
    {
        $from = $this->state;
        RestoreStateMachine::assertTransition($from, $to);

        $this->state = $to;
        if ($stage !== null) {
            $this->stage = $stage;
        }

        if ($from !== $to && $this->started_at === null && $to->isActive()) {
            $this->started_at = now();
        }
        if ($to->isTerminal() && $this->completed_at === null) {
            $this->completed_at = now();
        }

        $this->save();

        $this->logger()->log(
            $to === RestoreSessionState::Failed ? 'warning' : 'info',
            'restore session transition',
            ['from' => $from->value, 'to' => $to->value, 'stage' => $this->stage]
        );

        return $this;
    }

    public function heartbeat(): self
    {
        $this->heartbeat_at = now();
        $this->save();

        return $this;
    }

    public function recordError(BackupErrorCode $code, string $message): self
    {
        $this->error_code = $code->value;
        $this->error_message = $message;
        $this->save();

        $this->logger()->error('restore session error', [
            'error_code' => $code->value,
            'error_message' => $message,
        ]);

        return $this;
    }

    private function logger(): BackupLogger
    {
        return (new BackupLogger)->forSession(
            'restore',
            $this->id,
            $this->site_id,
            $this->state->value,
            $this->stage,
        );
    }
}
