<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SecuritySetting;
use App\Models\Site;
use App\Services\SiteTweaksSettingsService;
use App\Services\WordPressApiServiceFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PushSiteTweaksSettings implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public int $timeout = 60;

    public int $uniqueFor = 180; // P1-07: release stale unique lock after a hard kill (≈3× timeout)

    public function __construct(
        public Site $site,
    ) {
        $this->onQueue('security');
    }

    public function handle(): void
    {
        $settings = SecuritySetting::where('site_id', $this->site->id)
            ->whereIn('category', SiteTweaksSettingsService::TWEAK_CATEGORIES)
            ->get();

        $payload = $this->buildPayload($settings);

        if (empty($payload)) {
            return;
        }

        try {
            $api = app(WordPressApiServiceFactory::class)->make($this->site);
            $response = $api->request('POST', '/site-tweaks', $payload);

            if ($response->successful()) {
                $results = $response->json('results') ?? [];
                $this->processResults($results);
            } else {
                Log::warning('PushSiteTweaksSettings failed', [
                    'site_id' => $this->site->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                $this->markAllFailed('Plugin returned HTTP '.$response->status());
            }
        } catch (\Exception $e) {
            Log::error('PushSiteTweaksSettings exception', [
                'site_id' => $this->site->id,
                'error' => $e->getMessage(),
            ]);

            throw $e; // Let the queue retry
        }
    }

    protected function buildPayload($settings): array
    {
        $payload = [];

        foreach ($settings as $setting) {
            $category = $setting->category->value ?? $setting->category;
            $key = $setting->setting_key;
            $value = $setting->setting_value;
            $enabled = $setting->is_enabled;

            // Build per-category payloads: key â value (or key â false for disabled)
            if ($enabled) {
                $payload[$category][$key] = is_array($value) ? $value : $enabled;
            } else {
                $payload[$category][$key] = false;
            }
        }

        return $payload;
    }

    protected function processResults(array $results): void
    {
        $reported = [];

        foreach (SiteTweaksSettingsService::TWEAK_CATEGORIES as $category) {
            if (! isset($results[$category])) {
                continue;
            }

            $categoryResult = $results[$category];

            if (isset($categoryResult['success']) && $categoryResult['success']) {
                // Mark all enabled settings in this category as applied
                $appliedKeys = $categoryResult['applied'] ?? [];

                if (! empty($appliedKeys)) {
                    foreach ($appliedKeys as $key) {
                        $reported[] = ['category' => $category, 'key' => $key, 'applied' => true];
                    }
                } else {
                    // Fallback: mark all enabled settings as applied
                    $categorySettings = SecuritySetting::where('site_id', $this->site->id)
                        ->where('category', $category)
                        ->where('is_enabled', true)
                        ->get();

                    foreach ($categorySettings as $s) {
                        $reported[] = ['category' => $category, 'key' => $s->setting_key, 'applied' => true];
                    }
                }
            } elseif (isset($categoryResult['error'])) {
                $categorySettings = SecuritySetting::where('site_id', $this->site->id)
                    ->where('category', $category)
                    ->where('is_enabled', true)
                    ->get();

                foreach ($categorySettings as $s) {
                    $reported[] = [
                        'category' => $category,
                        'key' => $s->setting_key,
                        'failed' => true,
                        'reason' => $categoryResult['error'],
                    ];
                }
            }
        }

        if (! empty($reported)) {
            app(SiteTweaksSettingsService::class)->syncSettingsFromPlugin($this->site, $reported);
        }
    }

    protected function markAllFailed(string $reason): void
    {
        SecuritySetting::where('site_id', $this->site->id)
            ->whereIn('category', SiteTweaksSettingsService::TWEAK_CATEGORIES)
            ->where('is_enabled', true)
            ->whereNull('applied_at')
            ->update([
                'failed_at' => now(),
                'failure_reason' => $reason,
            ]);
    }

    public function uniqueId(): string
    {
        return 'push-tweaks-'.$this->site->id;
    }

    /**
     * P1-62: without a failed() handler, enabled-but-unapplied tweak settings sit
     * as "Pending" forever once retries are exhausted (or the worker is
     * hard-killed mid-push). Mark the stuck settings failed so the security
     * dashboard surfaces the real error state instead of a perpetual pending.
     */
    public function failed(?\Throwable $e): void
    {
        $reason = $e?->getMessage() ?? 'unknown error';

        Log::error('PushSiteTweaksSettings permanently failed', [
            'site_id' => $this->site->id,
            'error' => $reason,
        ]);

        $this->markAllFailed('Push failed: '.$reason);
    }
}
