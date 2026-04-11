<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SitePlugin;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckLicenseExpirations implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function uniqueId(): string
    {
        return 'check-license-expirations';
    }

    public function handle(): void
    {
        $expiring = SitePlugin::licensed()
            ->expiringLicenses(30)
            ->with('site')
            ->get();

        foreach ($expiring as $plugin) {
            if (! $plugin->site) {
                continue;
            }

            $daysLeft = (int) now()->diffInDays($plugin->license_expires_at, false);

            if (in_array($daysLeft, [30, 14, 7, 3, 1], true)) {
                NotificationService::notifySiteEvent(
                    $plugin->site,
                    'license_expiring',
                    'Plugin License Expiring',
                    "{$plugin->name} license expires in {$daysLeft} day(s) on {$plugin->license_expires_at->format('M j, Y')}.",
                    [
                        'Plugin' => $plugin->name,
                        'Site' => $plugin->site->name,
                        'Expires' => $plugin->license_expires_at->format('M j, Y'),
                        'Days Left' => $daysLeft,
                    ],
                    $daysLeft <= 3 ? 'critical' : 'warning'
                );
            }
        }

        $expired = SitePlugin::licensed()
            ->expiredLicenses()
            ->whereDate('license_expires_at', '>=', now()->subDay())
            ->with('site')
            ->get();

        foreach ($expired as $plugin) {
            if (! $plugin->site) {
                continue;
            }

            NotificationService::notifySiteEvent(
                $plugin->site,
                'license_expired',
                'Plugin License Expired',
                "{$plugin->name} license has expired.",
                [
                    'Plugin' => $plugin->name,
                    'Site' => $plugin->site->name,
                    'Expired' => $plugin->license_expires_at->format('M j, Y'),
                ],
                'critical'
            );
        }

        Log::info("License check: {$expiring->count()} expiring, {$expired->count()} newly expired");
    }
}
