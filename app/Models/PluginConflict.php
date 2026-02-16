<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PluginConflict extends Model
{
    use HasFactory;

    protected $fillable = [
        'plugin_a_slug',
        'plugin_b_slug',
        'conflict_type',
        'description',
        'severity',
        'source_url',
    ];

    public function getSeverityColorAttribute(): string
    {
        return match ($this->severity) {
            'critical' => 'red',
            'high' => 'red',
            'medium' => 'yellow',
            'low' => 'gray',
            default => 'gray',
        };
    }
}
