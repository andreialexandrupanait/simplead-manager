<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SafeUpdate extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'type',
        'slug',
        'name',
        'from_version',
        'to_version',
        'status',
        'health_check_results',
        'error_message',
        'auto_rollback',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'health_check_results' => 'array',
        'auto_rollback' => 'boolean',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
