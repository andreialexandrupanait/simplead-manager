<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail\Seo;

use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\PerformanceTest;
use App\Models\Site;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SeoCoreWebVitals extends Component
{
    use WithSiteAuthorization;

    public Site $site;

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
    }

    #[Computed]
    public function latestMobile(): ?PerformanceTest
    {
        return PerformanceTest::where('site_id', $this->site->id)
            ->where('device', 'mobile')
            ->where('status', 'completed')
            ->orderByDesc('tested_at')
            ->first();
    }

    #[Computed]
    public function latestDesktop(): ?PerformanceTest
    {
        return PerformanceTest::where('site_id', $this->site->id)
            ->where('device', 'desktop')
            ->where('status', 'completed')
            ->orderByDesc('tested_at')
            ->first();
    }

    #[Computed]
    public function recentHistory(): Collection
    {
        return PerformanceTest::where('site_id', $this->site->id)
            ->where('status', 'completed')
            ->orderByDesc('tested_at')
            ->limit(10)
            ->get();
    }

    /**
     * Return Tailwind color classes for a CWV metric value.
     *
     * @return array{text: string, bg: string, badge: string}
     */
    public function metricColorClasses(string $metric, float|int|null $value): array
    {
        if ($value === null) {
            return [
                'text' => 'text-gray-400 dark:text-gray-500',
                'bg' => 'bg-gray-100 dark:bg-gray-700',
                'badge' => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400',
            ];
        }

        $rating = match ($metric) {
            'lcp', 'field_lcp' => $value <= 2.5 ? 'green' : ($value <= 4.0 ? 'amber' : 'red'),
            'cls', 'field_cls' => $value <= 0.1 ? 'green' : ($value <= 0.25 ? 'amber' : 'red'),
            'field_inp' => $value <= 200 ? 'green' : ($value <= 500 ? 'amber' : 'red'),
            'fcp', 'field_fcp' => $value <= 1.8 ? 'green' : ($value <= 3.0 ? 'amber' : 'red'),
            default => 'gray',
        };

        return match ($rating) {
            'green' => [
                'text' => 'text-green-600 dark:text-green-400',
                'bg' => 'bg-green-50 dark:bg-green-900/20',
                'badge' => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
            ],
            'amber' => [
                'text' => 'text-amber-600 dark:text-amber-400',
                'bg' => 'bg-amber-50 dark:bg-amber-900/20',
                'badge' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
            ],
            'red' => [
                'text' => 'text-red-600 dark:text-red-400',
                'bg' => 'bg-red-50 dark:bg-red-900/20',
                'badge' => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
            ],
            default => [
                'text' => 'text-gray-400 dark:text-gray-500',
                'bg' => 'bg-gray-100 dark:bg-gray-700',
                'badge' => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400',
            ],
        };
    }

    public function render()
    {
        return view('livewire.sites.detail.seo.seo-core-web-vitals')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name.' — Core Web Vitals',
            ]);
    }
}
