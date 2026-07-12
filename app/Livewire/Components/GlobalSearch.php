<?php

declare(strict_types=1);

namespace App\Livewire\Components;

use App\Livewire\Traits\WithVisibleSites;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\PhpErrorLog;
use App\Models\Site;
use App\Models\SitePlugin;
use Livewire\Component;

class GlobalSearch extends Component
{
    use WithVisibleSites;

    public string $query = '';

    public array $results = [];

    public bool $isOpen = false;

    public function updatedQuery(): void
    {
        if (strlen($this->query) < 2) {
            $this->results = [];
            $this->isOpen = false;

            return;
        }

        $this->isOpen = true;
        $this->results = $this->performSearch($this->query);
    }

    private function performSearch(string $query): array
    {
        $results = [];
        $user = auth()->user();
        $isAdmin = (bool) $user?->isAdmin();
        $ids = $this->visibleSiteIds();

        // Sites
        $sites = Site::where(function ($q) use ($query) {
            $q->where('name', 'ilike', "%{$query}%")
                ->orWhere('url', 'ilike', "%{$query}%");
        })->visibleTo($user)->limit(5)->get();

        if ($sites->isNotEmpty()) {
            $results[] = [
                'category' => 'Sites',
                'items' => $sites->map(fn (Site $s) => [
                    'id' => $s->id,
                    'title' => $s->name,
                    'subtitle' => $s->url,
                    'url' => route('sites.overview', $s),
                ])->toArray(),
            ];
        }

        // Plugins
        $plugins = SitePlugin::where(function ($q) use ($query) {
            $q->where('name', 'ilike', "%{$query}%")
                ->orWhere('slug', 'ilike', "%{$query}%");
        })->whereHas('site')
            ->when($ids !== null, fn ($q) => $q->whereIn('site_id', $ids))
            ->with('site:id,name')->limit(5)->get();

        if ($plugins->isNotEmpty()) {
            $results[] = [
                'category' => 'Plugins',
                'items' => $plugins->map(fn (SitePlugin $p) => [
                    'id' => $p->id,
                    'title' => $p->name,
                    'subtitle' => $p->site?->name ?? '',
                    'url' => $p->site ? route('sites.plugins', $p->site) : '#',
                ])->toArray(),
            ];
        }

        // Clients — non-admins only see clients they're assigned to or that own
        // a site visible to them.
        $clients = Client::where(function ($q) use ($query) {
            $q->where('name', 'ilike', "%{$query}%")
                ->orWhere('company', 'ilike', "%{$query}%");
        })->when(! $isAdmin, fn ($q) => $q->where(function ($cq) use ($user, $ids) {
            $cq->whereIn('id', $user ? $user->assignedClients()->select('clients.id') : [])
                ->orWhereHas('sites', fn ($sq) => $sq->when($ids !== null, fn ($s) => $s->whereIn('sites.id', $ids)));
        }))->limit(5)->get();

        if ($clients->isNotEmpty()) {
            $results[] = [
                'category' => 'Clients',
                'items' => $clients->map(fn (Client $c) => [
                    'id' => $c->id,
                    'title' => $c->name,
                    'subtitle' => $c->company ?? '',
                    'url' => route('clients.show', $c),
                ])->toArray(),
            ];
        }

        // PHP Error Logs
        $errors = PhpErrorLog::where('message', 'ilike', "%{$query}%")
            ->whereHas('site')
            ->when($ids !== null, fn ($q) => $q->whereIn('site_id', $ids))
            ->with('site:id,name')
            ->limit(5)
            ->get();

        if ($errors->isNotEmpty()) {
            $results[] = [
                'category' => 'PHP Errors',
                'items' => $errors->map(fn (PhpErrorLog $e) => [
                    'id' => $e->id,
                    'title' => mb_strimwidth($e->message, 0, 80, '…'),
                    'subtitle' => $e->site?->name ?? '',
                    'url' => $e->site ? route('sites.overview', $e->site) : '#',
                ])->toArray(),
            ];
        }

        // Activity Logs — scope to the acting user's visible sites (admins see all).
        $activity = ActivityLog::where('title', 'ilike', "%{$query}%")
            ->when($ids !== null, fn ($q) => $q->whereIn('site_id', $ids))
            ->with('site:id,name')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        if ($activity->isNotEmpty()) {
            $results[] = [
                'category' => 'Activity',
                'items' => $activity->map(fn (ActivityLog $a) => [
                    'id' => $a->id,
                    'title' => $a->title,
                    'subtitle' => $a->site?->name ?? '',
                    'url' => $a->url ?? ($a->site ? route('sites.overview', $a->site) : '#'),
                ])->toArray(),
            ];
        }

        return $results;
    }

    public function close(): void
    {
        $this->query = '';
        $this->results = [];
        $this->isOpen = false;
    }

    public function render()
    {
        return view('livewire.components.global-search');
    }
}
