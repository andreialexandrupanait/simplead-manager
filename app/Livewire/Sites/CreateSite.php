<?php

namespace App\Livewire\Sites;

use App\Models\Client;
use App\Models\Site;
use App\Models\SitePreset;
use Livewire\Attributes\Url;
use Livewire\Component;

class CreateSite extends Component
{
    #[Url]
    public string $mode = 'connect';

    public string $name = '';
    public string $url = '';
    public ?int $clientId = null;
    public ?int $presetId = null;
    public string $notes = '';
    public string $bulkUrls = '';

    public function connectSite(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'url'  => 'required|url|max:255|unique:sites,url',
            'clientId' => 'nullable|exists:clients,id',
            'presetId' => 'nullable|exists:site_presets,id',
            'notes' => 'nullable|string|max:1000',
        ]);

        $site = Site::create([
            'name'      => $this->name,
            'url'       => $this->url,
            'user_id'   => auth()->id(),
            'client_id' => $this->clientId,
            'applied_preset_id' => $this->presetId,
            'notes'     => $this->notes,
            'type'      => 'wordpress',
            'status'    => 'pending',
        ]);

        // Note: Site::booted() handles creating monitors (performance, uptime, SSL, domain, link)
        // and dispatching FetchSiteFavicon + RunPerformanceTest automatically on creation.

        session()->flash('message', "Site \"{$site->name}\" connected successfully.");

        $this->redirect(route('sites.overview', $site), navigate: true);
    }

    public function bulkAddSites(): void
    {
        $this->validate([
            'bulkUrls' => 'required|string',
            'clientId' => 'nullable|exists:clients,id',
        ]);

        $lines = array_filter(array_map('trim', explode("\n", $this->bulkUrls)));
        $created = 0;
        $skipped = 0;

        foreach ($lines as $line) {
            $url = $line;
            if (!preg_match('#^https?://#i', $url)) {
                $url = 'https://' . $url;
            }
            $url = rtrim($url, '/');

            if (Site::where('url', $url)->exists()) {
                $skipped++;
                continue;
            }

            $name = parse_url($url, PHP_URL_HOST) ?? $url;

            $site = Site::create([
                'name'      => $name,
                'url'       => $url,
                'user_id'   => auth()->id(),
                'client_id' => $this->clientId,
                'applied_preset_id' => $this->presetId,
                'type'      => 'wordpress',
                'status'    => 'pending',
            ]);

            // Note: Site::booted() handles creating monitors and dispatching jobs automatically.

            $created++;
        }

        $message = "{$created} site(s) created.";
        if ($skipped > 0) {
            $message .= " {$skipped} skipped (already exist).";
        }

        session()->flash('message', $message);
        $this->redirect(route('dashboard'), navigate: true);
    }

    public function render()
    {
        return view('livewire.sites.create-site', [
            'clients' => Client::where('status', 'active')->orderBy('name')->get(),
            'presets' => SitePreset::orderBy('sort_order')->get(),
        ])->layout('components.layouts.app', ['title' => 'Add New Site']);
    }
}
