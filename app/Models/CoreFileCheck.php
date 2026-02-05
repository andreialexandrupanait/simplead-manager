<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
