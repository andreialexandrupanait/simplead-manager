<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $type
 * @property array|null $config
 * @property bool $is_default
 * @property bool $is_active
 * @property int $used_bytes
 * @property int|null $quota_bytes
 * @property \Illuminate\Support\Carbon|null $last_tested_at
 * @property bool|null $last_test_passed
 * @property string|null $last_test_error
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Backup> $backups
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\BackupConfig> $backupConfigs
 */
class StorageDestination extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'config',
        'is_default',
        'is_active',
        'used_bytes',
        'quota_bytes',
        'last_tested_at',
        'last_test_passed',
        'last_test_error',
    ];

    protected $casts = [
        'config' => 'array',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'used_bytes' => 'integer',
        'quota_bytes' => 'integer',
        'last_tested_at' => 'datetime',
        'last_test_passed' => 'boolean',
    ];

    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class);
    }

    public function backupConfigs(): HasMany
    {
        return $this->hasMany(BackupConfig::class);
    }

    public function getUsedFormattedAttribute(): string
    {
        return $this->formatBytes($this->used_bytes);
    }

    public function getUsagePercentAttribute(): ?float
    {
        if (! $this->quota_bytes) {
            return null;
        }

        return round(($this->used_bytes / $this->quota_bytes) * 100, 1);
    }

    public static function resolveForSite(Site $site): ?self
    {
        $config = $site->backupConfig;
        if ($config?->storage_destination_id) {
            return static::find($config->storage_destination_id);
        }

        return static::where('is_default', true)
            ->where('is_active', true)
            ->first()
            ?? static::where('is_active', true)->first();
    }

    protected function formatBytes(int $bytes): string
    {
        return \App\Helpers\FormatHelper::bytes($bytes);
    }
}
