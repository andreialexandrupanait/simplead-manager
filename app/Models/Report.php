<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReportStatus;
use App\Helpers\FormatHelper;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $site_id
 * @property int|null $report_template_id
 * @property int|null $report_schedule_id
 * @property string $title
 * @property \Illuminate\Support\Carbon $period_start
 * @property \Illuminate\Support\Carbon $period_end
 * @property string|null $file_path
 * @property string|null $file_name
 * @property int|null $file_size
 * @property int|null $page_count
 * @property \App\Enums\ReportStatus $status
 * @property string|null $error_message
 * @property string $trigger
 * @property bool $was_sent
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property array|null $sent_to
 * @property array|null $data_snapshot
 * @property string|null $view_token
 * @property \Illuminate\Support\Carbon|null $generated_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Site|null $site
 * @property-read \App\Models\ReportTemplate|null $reportTemplate
 * @property-read \App\Models\ReportSchedule|null $reportSchedule
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ReportRecommendation> $recommendations
 */
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
        'view_token',
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
