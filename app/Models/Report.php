<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReportStatus;
use App\Helpers\FormatHelper;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'status' => ReportStatus::class,
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

    public function recommendations(): HasMany
    {
        return $this->hasMany(ReportRecommendation::class);
    }

    public function getFileSizeFormattedAttribute(): string
    {
        if (! $this->file_size) {
            return '0 B';
        }

        return FormatHelper::bytes($this->file_size);
    }
}
