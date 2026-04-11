<?php

declare(strict_types=1);

namespace App\Livewire\Components;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\PhpErrorLog;
use App\Models\Site;
use App\Models\SitePlugin;
use Livewire\Component;

class GlobalSearch extends Component
{
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

        // Sites
        $sites = Site::where(function ($q) use ($query) {
            $q->where('name', 'ilike', "%{$query}%")
                ->orWhere('url', 'ilike', "%{$query}%");
        })->limit(5)->get();

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
        })->with('site:id,name')->limit(5)->get();

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

        // Clients
        $clients = Client::where(function ($q) use ($query) {
            $q->where('name', 'ilike', "%{$query}%")
                ->orWhere('company', 'ilike', "%{$query}%");
        })->limit(5)->get();

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

        // Activity Logs
        $activity = ActivityLog::where('title', 'ilike', "%{$query}%")
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
