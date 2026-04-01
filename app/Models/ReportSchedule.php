<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $site_id
 * @property int $report_template_id
 * @property bool $is_active
 * @property string $frequency
 * @property int|null $day_of_week
 * @property int|null $day_of_month
 * @property string $time
 * @property string $timezone
 * @property string $period
 * @property array|null $recipient_emails
 * @property bool $send_copy_to_admin
 * @property string|null $email_subject
 * @property string|null $email_body
 * @property \Illuminate\Support\Carbon|null $last_generated_at
 * @property \Illuminate\Support\Carbon|null $last_sent_at
 * @property \Illuminate\Support\Carbon|null $next_run_at
 * @property \Illuminate\Support\Carbon|null $reminder_sent_at
 * @property int $consecutive_failures
 * @property string|null $last_failure_reason
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Site|null $site
 * @property-read \App\Models\ReportTemplate|null $reportTemplate
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Report> $reports
 */
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
        'last_generated_at',
        'last_sent_at',
        'next_run_at',
        'reminder_sent_at',
        'consecutive_failures',
        'last_failure_reason',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'recipient_emails' => 'array',
        'send_copy_to_admin' => 'boolean',
        'last_generated_at' => 'datetime',
        'last_sent_at' => 'datetime',
        'next_run_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'consecutive_failures' => 'integer',
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
            $today = now($tz);

            // Try current month first
            $candidate = $today->copy()->setDay(min($dayOfMonth, $today->daysInMonth));
            $candidate->setTime((int) $hour, (int) $minute);

            if ($candidate->lte($today)) {
                // Target day/time already passed this month — use next month
                $nextMonth = $today->copy()->addMonthNoOverflow();
                $next = $nextMonth->setDay(min($dayOfMonth, $nextMonth->daysInMonth));
                $next->setTime((int) $hour, (int) $minute);
            } else {
                $next = $candidate;
            }
        }

        return $next->utc();
    }
}
