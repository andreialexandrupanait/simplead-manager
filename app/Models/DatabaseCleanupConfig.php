<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DatabaseCleanupConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'is_enabled',
        'frequency',
        'auto_clean_types',
        'next_cleanup_at',
        'last_cleanup_at',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'auto_clean_types' => 'array',
        'next_cleanup_at' => 'datetime',
        'last_cleanup_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
