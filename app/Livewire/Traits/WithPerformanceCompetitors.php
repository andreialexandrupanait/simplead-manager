<?php

declare(strict_types=1);

namespace App\Livewire\Traits;

use App\Exceptions\SsrfException;
use App\Models\PerformanceTest;
use App\Services\Security\SsrfGuard;
use Livewire\Attributes\Computed;

trait WithPerformanceCompetitors
{
    public string $newCompetitorUrl = '';

    public function addCompetitor(): void
    {
        $this->authorizeSiteModification($this->site);

        $this->validate(['newCompetitorUrl' => 'required|url|max:255']);

        if (! $this->monitor) {
            return;
        }

        // P2-15: competitor URLs are benchmarked (RunPerformanceTest), so reject a
        // URL that resolves to an internal/loopback/metadata address up front.
        try {
            app(SsrfGuard::class)->assertPublicUrl($this->newCompetitorUrl);
        } catch (SsrfException $e) {
            $this->addError('newCompetitorUrl', 'That URL is not a public address.');

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
        $this->authorizeSiteModification($this->site);

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
            $latestMobile = PerformanceTest::competitors()
                ->where('performance_monitor_id', $this->monitor->id)
                ->where('competitor_url', $url)
                ->where('device', 'mobile')
                ->where('status', 'completed')
                ->orderByDesc('tested_at')
                ->first();

            $latestDesktop = PerformanceTest::competitors()
                ->where('performance_monitor_id', $this->monitor->id)
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
