<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $site_id
 * @property string|null $wp_version
 * @property int $total_files
 * @property int $modified_count
 * @property int $missing_count
 * @property int $unknown_count
 * @property array|null $modified_files
 * @property array|null $missing_files
 * @property array|null $unknown_files
 * @property string $status
 * @property string|null $error_message
 * @property \Illuminate\Support\Carbon|null $checked_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Site|null $site
 */
class CoreFileCheck extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'wp_version',
        'total_files',
        'modified_count',
        'missing_count',
        'unknown_count',
        'modified_files',
        'missing_files',
        'unknown_files',
        'status',
        'error_message',
        'checked_at',
    ];

    protected $casts = [
        'total_files' => 'integer',
        'modified_count' => 'integer',
        'missing_count' => 'integer',
        'unknown_count' => 'integer',
        'modified_files' => 'array',
        'missing_files' => 'array',
        'unknown_files' => 'array',
        'checked_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'clean' => 'green',
            'modified' => 'red',
            'error' => 'yellow',
            default => 'gray',
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'clean' => 'Clean',
            'modified' => 'Modified',
            'error' => 'Error',
            default => 'Pending',
        };
    }
}
