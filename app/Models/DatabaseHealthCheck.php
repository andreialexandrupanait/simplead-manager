<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $site_id
 * @property int $total_size
 * @property int $total_tables
 * @property array|null $tables_data
 * @property array|null $largest_tables
 * @property array|null $tables_with_overhead
 * @property int $myisam_count
 * @property int $autoload_size
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $checked_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Site|null $site
 */
class DatabaseHealthCheck extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'total_size',
        'total_tables',
        'tables_data',
        'largest_tables',
        'tables_with_overhead',
        'myisam_count',
        'autoload_size',
        'status',
        'checked_at',
    ];

    protected $casts = [
        'total_size' => 'integer',
        'total_tables' => 'integer',
        'tables_data' => 'array',
        'largest_tables' => 'array',
        'tables_with_overhead' => 'array',
        'myisam_count' => 'integer',
        'autoload_size' => 'integer',
        'checked_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function getFormattedTotalSizeAttribute(): string
    {
        return static::formatBytes($this->total_size);
    }

    public function getFormattedAutoloadSizeAttribute(): string
    {
        return static::formatBytes($this->autoload_size);
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'healthy' => 'green',
            'warning' => 'yellow',
            'critical' => 'red',
            default => 'gray',
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return ucfirst($this->status);
    }

    public function getIssuesAttribute(): array
    {
        $issues = [];

        if ($this->total_size > 1_073_741_824) {
            $issues[] = 'Database size exceeds 1 GB ('.$this->formatted_total_size.')';
        }

        $totalOverhead = 0;
        foreach ($this->tables_with_overhead ?? [] as $table) {
            $totalOverhead += $table['overhead'] ?? 0;
        }
        if ($totalOverhead > 104_857_600) {
            $issues[] = 'Total table overhead exceeds 100 MB ('.static::formatBytes($totalOverhead).')';
        }

        if ($this->autoload_size > 1_048_576) {
            $issues[] = 'Autoload data exceeds 1 MB ('.$this->formatted_autoload_size.')';
        }

        if ($this->myisam_count > 0) {
            $issues[] = "{$this->myisam_count} table(s) using MyISAM engine (consider converting to InnoDB)";
        }

        foreach ($this->largest_tables ?? [] as $table) {
            $tableSize = ($table['data_size'] ?? 0) + ($table['index_size'] ?? 0);
            if ($tableSize > 524_288_000) {
                $issues[] = "Table '{$table['name']}' exceeds 500 MB (".static::formatBytes($tableSize).')';
            }
        }

        return $issues;
    }

    protected static function formatBytes(int $bytes): string
    {
        return \App\Helpers\FormatHelper::bytes($bytes);
    }
}
