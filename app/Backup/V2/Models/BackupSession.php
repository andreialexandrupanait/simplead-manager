<?php

declare(strict_types=1);

namespace App\Backup\V2\Models;

use App\Backup\V2\Enums\BackupErrorCode;
use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\StateMachine\BackupStateMachine;
use App\Backup\V2\Support\BackupLogger;
use App\Models\Backup;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * V2 backup session — the explicit FSM row driving a single backup run.
 *
 * @property int $id
 * @property int $site_id
 * @property int|null $backup_id
 * @property string $type
 * @property array|null $scope
 * @property array|null $exclusions
 * @property string|null $exclusion_policy_hash
 * @property string|null $scope_hash
 * @property string $resource_profile
 * @property \App\Backup\V2\Enums\BackupSessionState $state
 * @property string|null $stage
 * @property array|null $cursor
 * @property array|null $checkpoint
 * @property array|null $confirmed_objects
 * @property array|null $confirmed_parts
 * @property \Illuminate\Support\Carbon|null $heartbeat_at
 * @property int $attempt
 * @property int $retry_count
 * @property \Illuminate\Support\Carbon|null $next_retry_at
 * @property string $idempotency_key
 * @property string|null $error_code
 * @property string|null $error_message
 * @property string $format_version
 * @property int|null $full_base_id
 * @property int|null $chain_position
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 */
class BackupSession extends Model
{
    protected $table = 'backup_sessions';

    protected $fillable = [
        'site_id',
        'backup_id',
        'type',
        'scope',
        'exclusions',
        'exclusion_policy_hash',
        'scope_hash',
        'resource_profile',
        'state',
        'stage',
        'cursor',
        'checkpoint',
        'confirmed_objects',
        'confirmed_parts',
        'heartbeat_at',
        'attempt',
        'retry_count',
        'next_retry_at',
        'idempotency_key',
        'error_code',
        'error_message',
        'format_version',
        'full_base_id',
        'chain_position',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'scope' => 'array',
        'exclusions' => 'array',
        'cursor' => 'array',
        'checkpoint' => 'array',
        'confirmed_objects' => 'array',
        'confirmed_parts' => 'array',
        'state' => BackupSessionState::class,
        'attempt' => 'integer',
        'retry_count' => 'integer',
        'chain_position' => 'integer',
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
     * The V1 backups row this session materialises into (when one exists).
     */
    public function backup(): BelongsTo
    {
        return $this->belongsTo(Backup::class, 'backup_id');
    }

    /**
     * The full-backup session at the base of this incremental chain.
     */
    public function fullBase(): BelongsTo
    {
        return $this->belongsTo(self::class, 'full_base_id');
    }

    /**
     * Incremental sessions that reference this session as their chain base.
     */
    public function chainMembers(): HasMany
    {
        return $this->hasMany(self::class, 'full_base_id');
    }

    /**
     * Transition to a new FSM state through the state machine (idempotent on the
     * same state). Updates state + stage, writes a structured log line, and
     * saves. Illegal edges throw App\Backup\V2\Exceptions\IllegalStateTransition.
     */
    public function transitionTo(BackupSessionState $to, ?string $stage = null): self
    {
        $from = $this->state;
        BackupStateMachine::assertTransition($from, $to);

        $this->state = $to;
        if ($stage !== null) {
            $this->stage = $stage;
        }

        if ($to === BackupSessionState::Requested && $this->started_at === null) {
            $this->started_at = now();
        }
        if ($from !== $to && $this->started_at === null && $to->isActive()) {
            $this->started_at = now();
        }
        if ($to->isTerminal() && $this->completed_at === null) {
            $this->completed_at = now();
        }

        $this->save();

        $this->logger()->log(
            $to->isTerminal() && $to !== BackupSessionState::Completed ? 'warning' : 'info',
            'backup session transition',
            ['from' => $from->value, 'to' => $to->value, 'stage' => $this->stage]
        );

        return $this;
    }

    /**
     * Refresh the liveness heartbeat so stuck-recovery can tell a working session
     * from a dead one.
     */
    public function heartbeat(): self
    {
        $this->heartbeat_at = now();
        $this->save();

        return $this;
    }

    /**
     * Record a typed error code + message (does not itself transition state — the
     * caller decides retry_wait vs failed vs corrupt).
     */
    public function recordError(BackupErrorCode $code, string $message): self
    {
        $this->error_code = $code->value;
        $this->error_message = $message;
        $this->save();

        $this->logger()->error('backup session error', [
            'error_code' => $code->value,
            'error_message' => $message,
        ]);

        return $this;
    }

    private function logger(): BackupLogger
    {
        return (new BackupLogger)->forSession(
            'backup',
            $this->id,
            $this->site_id,
            $this->state->value,
            $this->stage,
        );
    }
}
