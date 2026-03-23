<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReportSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'report_template_id',
        'is_active',
        'frequency',
        'day_of_week',
        'day_of_month',
        'time',
        'timezone',
        'period',
        'recipient_emails',
        'send_copy_to_admin',
        'email_subject',
        'email_body',
        'client_name',
        'client_logo_path',
        'last_generated_at',
        'last_sent_at',
        'next_run_at',
        'reminder_sent_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'recipient_emails' => 'array',
        'send_copy_to_admin' => 'boolean',
        'last_generated_at' => 'datetime',
        'last_sent_at' => 'datetime',
        'next_run_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function reportTemplate(): BelongsTo
    {
        return $this->belongsTo(ReportTemplate::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    public function calculateNextRun(): Carbon
    {
        $tz = $this->timezone ?? 'Europe/Bucharest';
        [$hour, $minute] = explode(':', $this->time ?? '08:00');

        if ($this->frequency === 'weekly') {
            $next = now($tz)->next(Carbon::getDays()[$this->day_of_week ?? 0]);
            $next->setTime((int) $hour, (int) $minute);
        } else {
            $dayOfMonth = $this->day_of_month ?? 1;
            $next = now($tz)->addMonth()->setDay(min($dayOfMonth, now($tz)->addMonth()->daysInMonth));
            $next->setTime((int) $hour, (int) $minute);
        }

        return $next->utc();
    }
}
