<?php

namespace App\Livewire\Sites;

use App\Jobs\FetchSiteFavicon;
use App\Jobs\RunPerformanceTest;
use App\Models\Client;
use App\Models\Site;
use Livewire\Attributes\Url;
use Livewire\Component;

class CreateSite extends Component
{
    #[Url]
    public string $mode = 'connect';

    public string $name = '';
    public string $url = '';
    public ?int $clientId = null;
    public string $notes = '';
    public string $bulkUrls = '';

    public function connectSite(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'url'  => 'required|url|max:255',
            'clientId' => 'nullable|exists:clients,id',
            'notes' => 'nullable|string|max:1000',
        ]);

        $site = Site::create([
            'name'      => $this->name,
            'url'       => $this->url,
            'client_id' => $this->clientId,
            'notes'     => $this->notes,
            'type'      => 'wordpress',
            'status'    => 'pending',
        ]);

        // Dispatch favicon fetch
        FetchSiteFavicon::dispatch($site);

        // Create performance monitor if missing and dispatch test (will fetch screenshot)
        if (!$site->performanceMonitor) {
            $monitor = $site->performanceMonitor()->create([
                'is_active' => true,
                'frequency' => 'daily',
                'test_time' => '04:00',
            ]);
            RunPerformanceTest::dispatch($monitor, 'mobile');
        }

        session()->flash('message', "Site \"{$site->name}\" connected successfully.");

        $this->redirect(route('sites.settings', $site), navigate: true);
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
                'client_id' => $this->clientId,
                'type'      => 'wordpress',
                'status'    => 'pending',
            ]);

            // Dispatch favicon fetch
            FetchSiteFavicon::dispatch($site);

            // Create performance monitor if missing and dispatch test (will fetch screenshot)
            if (!$site->performanceMonitor) {
                $monitor = $site->performanceMonitor()->create([
                    'is_active' => true,
                    'frequency' => 'daily',
                    'test_time' => '04:00',
                ]);
                // Delay to avoid PageSpeed API rate limiting
                RunPerformanceTest::dispatch($monitor, 'mobile')->delay(now()->addSeconds($created * 5));
            }

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
        ])->layout('components.layouts.app', ['title' => 'Add New Site']);
    }
}
