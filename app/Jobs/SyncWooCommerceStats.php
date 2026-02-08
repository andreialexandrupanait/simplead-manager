<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Notifications\NotificationService;
use App\Services\WooCommerceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncWooCommerceStats implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 60;
    public array $backoff = [15, 30];

    public function __construct(
        public Site $site,
    ) {}

    public function uniqueId(): string
    {
        return 'woo-sync-' . $this->site->id;
    }

    public function handle(WooCommerceService $service): void
    {
        $service->syncDailyStats($this->site);
        $service->checkAlerts($this->site);

        // Notify for new unacknowledged alerts
        $newAlerts = $this->site->wooCommerceAlerts()
            ->unacknowledged()
            ->where('created_at', '>=', now()->subMinutes(5))
            ->get();

        foreach ($newAlerts as $alert) {
            $event = match ($alert->type) {
                'low_stock' => 'woo_low_stock',
                'out_of_stock' => 'woo_out_of_stock',
                default => null,
            };

            if ($event) {
                NotificationService::notifySiteEvent(
                    $this->site,
                    $event,
                    ucfirst(str_replace('_', ' ', $alert->type)),
                    $alert->message,
                    array_filter([
                        'Product' => $alert->product_name,
                        'Type' => ucfirst(str_replace('_', ' ', $alert->type)),
                    ]),
                    'warning',
                );
            }
        }
    }
}
