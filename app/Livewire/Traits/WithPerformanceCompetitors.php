<?php

declare(strict_types=1);

namespace App\Livewire\Traits;

use App\Models\PerformanceTest;
use Livewire\Attributes\Computed;

trait WithPerformanceCompetitors
{
    public string $newCompetitorUrl = '';

    public function addCompetitor(): void
    {
        $this->validate(['newCompetitorUrl' => 'required|url|max:255']);

        if (! $this->monitor) {
            return;
        }

        $urls = $this->monitor->competitor_urls ?? [];
        if (count($urls) >= 5) {
            $this->addError('newCompetitorUrl', 'Maximum 5 competitors allowed.');

            return;
        }

        $urls[] = $this->newCompetitorUrl;
        $this->monitor->update(['competitor_urls' => array_values(array_unique($urls))]);
        $this->newCompetitorUrl = '';
        unset($this->monitor);
    }

    public function removeCompetitor(int $index): void
    {
        if (! $this->monitor) {
            return;
        }

        $urls = $this->monitor->competitor_urls ?? [];
        unset($urls[$index]);
        $this->monitor->update(['competitor_urls' => array_values($urls)]);
        unset($this->monitor);
    }

    #[Computed]
    public function competitorComparison(): array
    {
        if (! $this->monitor || empty($this->monitor->competitor_urls)) {
            return [];
        }

        $results = [];
        foreach ($this->monitor->competitor_urls as $url) {
            $latestMobile = PerformanceTest::where('performance_monitor_id', $this->monitor->id)
                ->where('is_competitor', true)
                ->where('competitor_url', $url)
                ->where('device', 'mobile')
                ->where('status', 'completed')
                ->orderByDesc('tested_at')
                ->first();

            $latestDesktop = PerformanceTest::where('performance_monitor_id', $this->monitor->id)
                ->where('is_competitor', true)
                ->where('competitor_url', $url)
                ->where('device', 'desktop')
                ->where('status', 'completed')
                ->orderByDesc('tested_at')
                ->first();

            $results[] = [
                'url' => $url,
                'domain' => parse_url($url, PHP_URL_HOST),
                'mobile_score' => $latestMobile?->performance_score,
                'desktop_score' => $latestDesktop?->performance_score,
                'tested_at' => $latestMobile->tested_at ?? $latestDesktop?->tested_at,
            ];
        }

        return $results;
    }
}
