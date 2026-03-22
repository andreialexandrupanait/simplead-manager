<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppBackupConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'is_enabled',
        'frequency',
        'time',
        'day_of_week',
        'day_of_month',
        'timezone',
        'type',
        'components',
        'storage_destination_id',
        'retention_type',
        'retention_value',
        'encrypt_backup',
        'encryption_password',
        'last_backup_at',
        'next_backup_at',
        'last_backup_status',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'encrypt_backup' => 'boolean',
        'components' => 'array',
        'day_of_week' => 'integer',
        'day_of_month' => 'integer',
        'retention_value' => 'integer',
        'last_backup_at' => 'datetime',
        'next_backup_at' => 'datetime',
    ];

    public function storageDestination(): BelongsTo
    {
        return $this->belongsTo(StorageDestination::class);
    }

    public static function instance(): self
    {
        return static::firstOrCreate([], [
            'is_enabled' => false,
            'frequency' => 'daily',
            'time' => '02:00',
            'timezone' => 'Europe/Bucharest',
            'type' => 'full',
            'components' => ['database', 'env', 'storage'],
            'retention_type' => 'count',
            'retention_value' => 7,
            'encrypt_backup' => false,
        ]);
    }

    public function setEncryptionPasswordAttribute(?string $value): void
    {
        $this->attributes['encryption_password'] = $value ? encrypt($value) : null;
    }

    public function getEncryptionPasswordAttribute(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return decrypt($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function calculateNextBackupAt(): Carbon
    {
        $tz = $this->timezone ?? 'Europe/Bucharest';
        $now = now($tz);
        [$hour, $minute] = explode(':', $this->time ?? '02:00');

        $next = match ($this->frequency) {
            'weekly' => $now->copy()->next((int) ($this->day_of_week ?? 0))->setTime((int) $hour, (int) $minute),
            'monthly' => $now->copy()->addMonth()->day(min((int) ($this->day_of_month ?? 1), 28))->setTime((int) $hour, (int) $minute),
            default => $now->copy()->addDay()->setTime((int) $hour, (int) $minute), // daily
        };

        // If the calculated time is in the past, advance
        if ($next->lte($now)) {
            $next = match ($this->frequency) {
                'weekly' => $next->addWeek(),
                'monthly' => $next->addMonth(),
                default => $next->addDay(),
            };
        }

        return $next->utc();
    }
}
