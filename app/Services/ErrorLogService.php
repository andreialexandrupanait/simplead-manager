<?php

namespace App\Services;

use App\Models\ErrorLog;
use App\Models\Site;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ErrorLogService
{
    public static function sync(Site $site): array
    {
        $api = new WordPressApiService($site);
        $data = $api->getErrorLogs();

        $new = 0;
        $updated = 0;

        foreach ($data['errors'] ?? [] as $error) {
            $hash = md5(($error['message'] ?? '') . '|' . ($error['file_path'] ?? '') . '|' . ($error['line_number'] ?? ''));

            $existing = ErrorLog::where('site_id', $site->id)
                ->where('error_hash', $hash)
                ->first();

            if ($existing) {
                $existing->update([
                    'count' => $existing->count + 1,
                    'last_seen_at' => now(),
                    'stack_trace' => $error['stack_trace'] ?? $existing->stack_trace,
                    'context' => $error['context'] ?? $existing->context,
                ]);
                $updated++;
            } else {
                $errorLog = ErrorLog::create([
                    'site_id' => $site->id,
                    'error_hash' => $hash,
                    'level' => $error['level'] ?? 'error',
                    'message' => $error['message'] ?? 'Unknown error',
                    'file_path' => $error['file_path'] ?? null,
                    'line_number' => $error['line_number'] ?? null,
                    'stack_trace' => $error['stack_trace'] ?? null,
                    'context' => $error['context'] ?? null,
                    'count' => 1,
                    'first_seen_at' => now(),
                    'last_seen_at' => now(),
                ]);
                $new++;

                // Notify on new fatal errors (throttle: 30 min per site)
                if ($errorLog->level === 'fatal') {
                    $cacheKey = "fatal_notification_site_{$site->id}";
                    if (!Cache::has($cacheKey)) {
                        NotificationService::notifySiteEvent(
                            site: $site,
                            event: 'fatal_error_detected',
                            title: "Fatal error detected on {$site->name}",
                            message: $errorLog->message,
                            fields: [
                                'File' => $errorLog->file_path ? "{$errorLog->file_path}:{$errorLog->line_number}" : 'Unknown',
                            ],
                            severity: 'critical',
                        );
                        Cache::put($cacheKey, true, now()->addMinutes(30));
                    }
                }
            }
        }

        $total = ErrorLog::where('site_id', $site->id)->unresolved()->count();

        ActivityLogger::log(
            type: 'error_log',
            severity: $new > 0 ? 'warning' : 'info',
            title: "Error log sync for {$site->name}",
            description: "{$new} new, {$updated} updated, {$total} total unresolved",
            site: $site,
            icon: 'alert-triangle',
            url: route('sites.errors', $site),
        );

        return ['new' => $new, 'updated' => $updated, 'total' => $total];
    }

    public static function resolve(ErrorLog $errorLog, User $user): void
    {
        $errorLog->update([
            'is_resolved' => true,
            'resolved_by' => $user->id,
            'resolved_at' => now(),
        ]);
    }

    public static function resolveAll(Site $site, User $user): int
    {
        return ErrorLog::where('site_id', $site->id)
            ->where('is_resolved', false)
            ->update([
                'is_resolved' => true,
                'resolved_by' => $user->id,
                'resolved_at' => now(),
            ]);
    }
}
