<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NotificationEscalationRule;
use App\Models\NotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessNotificationEscalations implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function handle(): void
    {
        $rules = NotificationEscalationRule::where('is_active', true)
            ->with(['sourceChannel', 'escalationChannel'])
            ->get();

        if ($rules->isEmpty()) {
            return;
        }

        foreach ($rules as $rule) {
            $this->processRule($rule);
        }
    }

    private function processRule(NotificationEscalationRule $rule): void
    {
        // Find unacknowledged, non-escalated notifications for this source channel
        $cutoff = now()->subMinutes($rule->delay_minutes);

        $pending = NotificationLog::where('notification_channel_id', $rule->source_channel_id)
            ->where('severity', $rule->severity)
            ->where('status', 'sent')
            ->where('escalated', false)
            ->whereNull('acknowledged_at')
            ->where('created_at', '<=', $cutoff)
            ->where('created_at', '>=', now()->subHours(24)) // Only last 24h
            ->limit(10)
            ->get();

        foreach ($pending as $log) {
            try {
                // Send escalation to the escalation channel
                SendNotificationJob::dispatch(
                    channel: $rule->escalationChannel,
                    site: $log->site,
                    event: $log->event,
                    title: '[ESCALATION] '.($log->metadata['title'] ?? $log->event),
                    message: ($log->message ?? 'No details').' — Not acknowledged after '.$rule->delay_minutes.' minutes.',
                    fields: [],
                    severity: $log->severity ?? 'critical',
                );

                $log->update(['escalated' => true]);

                Log::info("Escalated notification {$log->id} from channel {$rule->source_channel_id} to {$rule->escalation_channel_id}");
            } catch (\Throwable $e) {
                Log::warning("Failed to escalate notification {$log->id}: {$e->getMessage()}");
            }
        }
    }
}
