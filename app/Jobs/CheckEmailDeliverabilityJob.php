<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Services\EmailDeliverabilityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckEmailDeliverabilityJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public array $backoff = [30, 60];

    public function __construct(
        public Site $site,
    ) {}

    public function uniqueId(): string
    {
        return 'email-check-'.$this->site->id;
    }

    public function handle(): void
    {
        try {
            EmailDeliverabilityService::check($this->site);
        } catch (\Exception $e) {
            Log::warning("Email deliverability check failed for site {$this->site->id}", [
                'exception' => get_class($e),
                'code' => $e->getCode(),
            ]);
            throw $e;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error("Email deliverability check permanently failed for site {$this->site->id}", [
            'exception' => $exception ? get_class($exception) : 'Unknown',
            'code' => $exception?->getCode(),
        ]);
    }
}
