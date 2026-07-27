<?php

declare(strict_types=1);

namespace App\Backup\V2\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P6 verification record for a V2 backup — the evidence a completed backup was
 * checked. `kind` is 'create' (cheap, produced at completion) or 'deep' (sampled,
 * scheduled). `status` is 'passed', 'failed' or 'corrupt'. Only a PASSED create
 * verification stamps `backup_sessions.verified_at`, which retention reads for
 * its keep-last-verified guarantee.
 *
 * @property int $id
 * @property int $backup_session_id
 * @property string $kind
 * @property string $status
 * @property int $object_count
 * @property int|null $sample_size
 * @property string|null $composite_checksum
 * @property array<string,mixed>|null $checks
 * @property string|null $error
 * @property \Illuminate\Support\Carbon|null $verified_at
 */
class BackupVerification extends Model
{
    protected $table = 'backup_verifications';

    public const KIND_CREATE = 'create';

    public const KIND_DEEP = 'deep';

    public const STATUS_PASSED = 'passed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CORRUPT = 'corrupt';

    protected $fillable = [
        'backup_session_id',
        'kind',
        'status',
        'object_count',
        'sample_size',
        'composite_checksum',
        'checks',
        'error',
        'verified_at',
    ];

    protected $casts = [
        'object_count' => 'integer',
        'sample_size' => 'integer',
        'checks' => 'array',
        'verified_at' => 'datetime',
    ];

    public function passed(): bool
    {
        return $this->status === self::STATUS_PASSED;
    }

    /** @return BelongsTo<BackupSession, $this> */
    public function backupSession(): BelongsTo
    {
        return $this->belongsTo(BackupSession::class, 'backup_session_id');
    }
}
