<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\DailyDigestMail;
use App\Models\Site;
use App\Models\UptimeIncident;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendDailyDigest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public array $backoff = [30, 60];

    public function __construct()
    {
        // P2-70: run on the dedicated notifications supervisor rather than the
        // low-priority `default` queue behind long-running general jobs.
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $digest = $this->gatherDigest();

        // P3-29: only mail users who have not opted out of the digest. Previously
        // every user was force-mailed regardless of their preference.
        foreach (User::where('digest_enabled', true)->cursor() as $user) {
            try {
                Mail::to($user->email)->queue(new DailyDigestMail($digest));
            } catch (\Throwable $e) {
                Log::warning("Failed to queue daily digest for user {$user->id}: {$e->getMessage()}");
            }
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('SendDailyDigest job failed: '.($exception?->getMessage() ?? 'Unknown error'));
    }

    protected function gatherDigest(): array
    {
        $yesterday = now()->subDay();

        return [
            'date' => now()->format('M d, Y'),
            'total_sites' => Site::count(),
            'sites_up' => Site::where('is_up', true)->count(),
            'sites_down' => Site::where('is_up', false)->count(),
            'incidents_24h' => UptimeIncident::where('started_at', '>=', $yesterday)->count(),
            'resolved_24h' => UptimeIncident::where('resolved_at', '>=', $yesterday)->count(),
            'backups_24h' => DB::table('backups')
                ->where('created_at', '>=', $yesterday)
                ->where('status', 'completed')
                ->count(),
            'backups_failed_24h' => DB::table('backups')
                ->where('created_at', '>=', $yesterday)
                ->where('status', 'failed')
                ->count(),
            'updates_available' => DB::table('sites')
                ->where('is_connected', true)
                ->where('pending_updates_count', '>', 0)
                ->count(),
        ];
    }
}
