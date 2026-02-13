<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RetentionCleanup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 1;

    public function handle(): void
    {
        $cleaned = [];

        // Uptime checks — 45 days
        $cleaned['uptime_checks'] = DB::table('uptime_checks')
            ->where('checked_at', '<=', now()->subDays(45))
            ->delete();

        // Performance tests — 60 days
        $cleaned['performance_tests'] = DB::table('performance_tests')
            ->where('created_at', '<=', now()->subDays(60))
            ->delete();

        // Security scans — 90 days
        $cleaned['security_scans'] = DB::table('security_scans')
            ->where('scanned_at', '<=', now()->subDays(90))
            ->delete();

        // Analytics cache — 60 days
        $cleaned['analytics_cache'] = DB::table('analytics_cache')
            ->where('created_at', '<=', now()->subDays(60))
            ->delete();

        // Search console cache — 60 days
        $cleaned['search_console_cache'] = DB::table('search_console_cache')
            ->where('created_at', '<=', now()->subDays(60))
            ->delete();

        // Activity logs — 180 days
        $cleaned['activity_logs'] = DB::table('activity_logs')
            ->where('created_at', '<=', now()->subDays(180))
            ->delete();

        // Notification logs — 90 days
        if (DB::getSchemaBuilder()->hasTable('notification_logs')) {
            $cleaned['notification_logs'] = DB::table('notification_logs')
                ->where('created_at', '<=', now()->subDays(90))
                ->delete();
        }

        // Expired backups
        $expiredBackups = DB::table('backups')
            ->where('expires_at', '<=', now())
            ->where('is_locked', false)
            ->get();

        foreach ($expiredBackups as $backup) {
            try {
                if ($backup->storage_destination_id && $backup->file_path) {
                    $destination = \App\Models\StorageDestination::find($backup->storage_destination_id);
                    if ($destination) {
                        $driver = \App\Services\Backup\Storage\StorageFactory::make($destination);
                        $driver->delete($backup->file_path);
                        $destination->decrement('used_bytes', max(0, $backup->file_size ?? 0));
                    }
                }
                DB::table('backups')->where('id', $backup->id)->delete();
            } catch (\Exception $e) {
                Log::warning("Failed to clean expired backup {$backup->id}: {$e->getMessage()}");
            }
        }
        $cleaned['expired_backups'] = count($expiredBackups);

        // Expired IP rules
        $cleaned['expired_ip_rules'] = DB::table('ip_rules')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->delete();

        // Old audit logs — 90 days
        if (DB::getSchemaBuilder()->hasTable('wp_audit_logs')) {
            $cleaned['wp_audit_logs'] = DB::table('wp_audit_logs')
                ->where('action_at', '<=', now()->subDays(90))
                ->delete();
        }

        // Old blocked requests — 30 days
        if (DB::getSchemaBuilder()->hasTable('blocked_requests')) {
            $cleaned['blocked_requests'] = DB::table('blocked_requests')
                ->where('blocked_at', '<=', now()->subDays(30))
                ->delete();
        }

        // Expired rollback points
        try {
            app(\App\Services\RollbackService::class)->cleanExpired();
        } catch (\Exception $e) {
            // Ignore if service doesn't exist
        }

        // Resource checks — 90 days
        if (DB::getSchemaBuilder()->hasTable('resource_checks')) {
            $cleaned['resource_checks'] = DB::table('resource_checks')
                ->where('checked_at', '<=', now()->subDays(90))
                ->delete();
        }

        $total = array_sum($cleaned);
        Log::info("Retention cleanup complete: {$total} records cleaned", $cleaned);
    }
}
