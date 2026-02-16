<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Report extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'report_template_id',
        'report_schedule_id',
        'title',
        'period_start',
        'period_end',
        'file_path',
        'file_name',
        'file_size',
        'page_count',
        'status',
        'error_message',
        'trigger',
        'was_sent',
        'sent_at',
        'sent_to',
        'data_snapshot',
        'generated_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'was_sent' => 'boolean',
        'sent_to' => 'array',
        'data_snapshot' => 'array',
        'generated_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function reportTemplate(): BelongsTo
    {
        return $this->belongsTo(ReportTemplate::class);
    }

    public function reportSchedule(): BelongsTo
    {
        return $this->belongsTo(ReportSchedule::class);
    }

    public function getFileSizeFormattedAttribute(): string
    {
        $bytes = $this->file_size;
        if (!$bytes) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = floor(log($bytes, 1024));
        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }
}
