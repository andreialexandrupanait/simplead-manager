<?php

declare(strict_types=1);

namespace App\Backup\V2\Models;

use App\Models\Backup;
use App\Models\Site;
use App\Models\StorageDestination;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P6 read-only index entry for a LEGACY backup (see LegacyImportService). A pure
 * catalogue row describing where a pre-existing artifact lives and how it
 * classifies (docs/backup/LEGACY-BACKUPS-DECISION.md categories A–F). Never
 * implies ownership of the legacy write path — the objects are NEVER mutated.
 *
 * @property int $id
 * @property int|null $backup_id
 * @property int|null $storage_destination_id
 * @property int|null $site_id
 * @property string|null $format
 * @property string $classification
 * @property string|null $path
 * @property bool $object_present
 * @property string|null $composite_checksum
 * @property int $total_size
 * @property int $file_count
 * @property array<string,mixed>|null $metadata
 * @property \Illuminate\Support\Carbon|null $discovered_at
 */
class LegacyBackupIndexEntry extends Model
{
    protected $table = 'legacy_backup_index';

    // Classifications mirror docs/backup/LEGACY-BACKUPS-DECISION.md categories A–F.
    public const CLASS_VALID = 'valid';                                 // A — verified restore point

    public const CLASS_VERIFICATION_REQUIRED = 'verification_required'; // B — present, unverified

    public const CLASS_RECOVERABLE = 'recoverable';                     // C

    public const CLASS_INCOMPLETE = 'incomplete';                       // D — failed/cancelled

    public const CLASS_ORPHANED = 'orphaned';                           // E — object, no row

    public const CLASS_PHANTOM = 'phantom';                             // F — row, no object

    public const CLASS_QUARANTINED = 'quarantined';

    public const CLASS_INVALID = 'invalid';

    public const CLASS_UNKNOWN = 'unknown';

    protected $fillable = [
        'backup_id',
        'storage_destination_id',
        'site_id',
        'format',
        'classification',
        'path',
        'object_present',
        'composite_checksum',
        'total_size',
        'file_count',
        'metadata',
        'discovered_at',
    ];

    protected $casts = [
        'object_present' => 'boolean',
        'total_size' => 'integer',
        'file_count' => 'integer',
        'metadata' => 'array',
        'discovered_at' => 'datetime',
    ];

    /** @return BelongsTo<Backup, $this> */
    public function backup(): BelongsTo
    {
        return $this->belongsTo(Backup::class, 'backup_id');
    }

    /** @return BelongsTo<StorageDestination, $this> */
    public function storageDestination(): BelongsTo
    {
        return $this->belongsTo(StorageDestination::class, 'storage_destination_id');
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }
}
