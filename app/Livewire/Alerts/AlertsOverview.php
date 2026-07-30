<?php

declare(strict_types=1);

namespace App\Livewire\Alerts;

use App\Enums\HealthLevel;
use App\Livewire\Traits\WithVisibleSites;
use App\Models\Site;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Everything that needs a human, on its own screen.
 *
 * The sidebar has had an "Alerts" item for a while, but it pointed back at the
 * landing page with `?tab=alerts` — so the fleet overview doubled as the alert
 * list, and the landing page opened with a wall of red before you could see
 * your sites. This is that screen, and the landing page goes back to being an
 * overview.
 *
 * Grouped by client because that is how you act on it: one client's three
 * broken sites are one phone call, not three.
 */
class AlertsOverview extends Component
{
    use WithVisibleSites;

    /** all | down | disconnected | health */
    public string $filter = 'all';

    /**
     * @return array{groups: list<array{client: string, severity: int, sites: list<array<string, mixed>>}>, total: int, healthy: int}
     */
    #[Computed]
    public function alerts(): array
    {
        $query = Site::query()->needsAttention()->with('client');

        $ids = $this->visibleSiteIds();
        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }

        $sites = $query->get()->filter(fn (Site $site): bool => match ($this->filter) {
            'down' => $site->is_up === false,
            'disconnected' => $site->is_connected === false,
            'health' => $site->health_score !== null && (int) $site->health_score < HealthLevel::WARNING_THRESHOLD,
            default => true,
        });

        $groups = [];

        foreach ($sites as $site) {
            $isDown = $site->is_up === false;
            $isDisconnected = $site->is_connected === false;

            // Down or unreachable is critical; a poor score alone is a warning.
            $severity = ($isDown || $isDisconnected) ? 0 : 1;

            $reasons = [];
            if ($isDown) {
                $reasons[] = __('down');
            }
            if ($isDisconnected) {
                $reasons[] = __('disconnected');
            }
            if (! $isDown && ! $isDisconnected && $site->health_score !== null) {
                $reasons[] = __('health :n', ['n' => (int) $site->health_score]);
            }

            $client = $site->client !== null ? $site->client->name : __('No client');

            $groups[$client]['client'] = $client;
            $groups[$client]['severity'] = min($groups[$client]['severity'] ?? 9, $severity);
            $groups[$client]['sites'][] = [
                'id' => $site->id,
                'name' => $site->name,
                'url' => route('sites.overview', $site),
                'severity' => $severity,
                'reasons' => implode(' · ', $reasons),
                'last_seen' => $site->last_synced_at?->diffForHumans(),
            ];
        }

        foreach ($groups as &$group) {
            usort($group['sites'], fn (array $a, array $b): int => $a['severity'] <=> $b['severity']);
        }
        unset($group);

        uasort($groups, fn (array $a, array $b): int => $a['severity'] <=> $b['severity']);

        return [
            'groups' => array_values($groups),
            'total' => $sites->count(),
            'healthy' => $this->healthyCount(),
        ];
    }

    /** @return array<string, int> */
    #[Computed]
    public function counts(): array
    {
        $base = fn () => tap(Site::query(), function ($q) {
            $ids = $this->visibleSiteIds();
            if ($ids !== null) {
                $q->whereIn('id', $ids);
            }
        });

        return [
            'all' => $base()->needsAttention()->count(),
            'down' => $base()->where('is_up', false)->count(),
            'disconnected' => $base()->where('is_connected', false)->count(),
            'health' => $base()->where('health_score', '<', HealthLevel::WARNING_THRESHOLD)->count(),
        ];
    }

    private function healthyCount(): int
    {
        $query = Site::query()->whereNot(fn ($q) => $q->needsAttention());

        $ids = $this->visibleSiteIds();
        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }

        return $query->count();
    }

    public function render()
    {
        return view('livewire.alerts.alerts-overview')
            ->layout('components.layouts.app', ['title' => __('Alerte')]);
    }
}
