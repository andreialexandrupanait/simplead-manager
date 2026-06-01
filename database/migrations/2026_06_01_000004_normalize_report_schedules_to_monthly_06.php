<?php

declare(strict_types=1);

use App\Models\ReportSchedule;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        ReportSchedule::query()->each(function (ReportSchedule $schedule) {
            $schedule->update([
                'frequency' => 'monthly',
                'day_of_month' => 1,
                'day_of_week' => null,
                'time' => '05:00',
                'timezone' => 'Europe/Bucharest',
                'send_copy_to_admin' => true,
            ]);

            $schedule->update(['next_run_at' => $schedule->calculateNextRun()]);
        });
    }

    public function down(): void
    {
        // No-op: original values are not recoverable.
    }
};
