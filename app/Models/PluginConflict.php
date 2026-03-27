<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $plugin_a_slug
 * @property string $plugin_b_slug
 * @property string $conflict_type
 * @property string $description
 * @property string $severity
 * @property string|null $source_url
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
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
