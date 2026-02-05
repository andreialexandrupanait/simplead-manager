<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResourceCheck extends Model
{
    protected $fillable = [
        'site_id',
        'cpu_usage',
        'memory_used',
        'memory_total',
        'memory_percentage',
        'disk_used',
        'disk_total',
        'disk_percentage',
        'load_average_1',
        'load_average_5',
        'load_average_15',
        'is_available',
        'checked_at',
    ];

    protected $casts = [
        'checked_at' => 'datetime',
        'is_available' => 'boolean',
        'cpu_usage' => 'decimal:2',
        'memory_percentage' => 'decimal:2',
        'disk_percentage' => 'decimal:2',
        'load_average_1' => 'decimal:2',
        'load_average_5' => 'decimal:2',
        'load_average_15' => 'decimal:2',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
