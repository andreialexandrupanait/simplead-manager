<?php

namespace App\Livewire\StatusPages;

use App\Models\Client;
use App\Models\Site;
use App\Models\StatusPage;
use App\Models\StatusPageIncident;
use App\Models\StatusPageSite;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

class StatusPageEdit extends Component
{
    use AuthorizesRequests;

    public ?StatusPage $statusPage = null;

    // Form fields
    public string $title = '';
    public string $slug = '';
    public ?string $description = '';
    public ?string $logoUrl = '';
    public string $primaryColor = '#7C3AED';
    public bool $isPublic = true;
    public bool $showUptimePercentage = true;
    public bool $showResponseTime = false;
    public bool $showIncidentHistory = true;
    public int $incidentHistoryDays = 90;
    public bool $autoIncidents = true;
    public string $password = '';
    public ?int $clientId = null;
    public array $selectedSites = [];
    public ?string $customDomain = '';

    // Incident management
    public string $incidentTitle = '';
    public string $incidentDescription = '';
    public string $incidentSeverity = 'minor';
    public ?int $incidentSiteId = null;
    public string $updateMessage = '';
    public string $updateStatus = 'investigating';

    public function mount(?StatusPage $statusPage = null): void
    {
        if ($statusPage?->exists) {
            $this->authorize('update', $statusPage);
            $this->statusPage = $statusPage;
            $this->title = $statusPage->title;
            $this->slug = $statusPage->slug;
            $this->description = $statusPage->description ?? '';
            $this->logoUrl = $statusPage->logo_url ?? '';
            $this->primaryColor = $statusPage->primary_color;
            $this->isPublic = $statusPage->is_public;
            $this->showUptimePercentage = $statusPage->show_uptime_percentage;
            $this->showResponseTime = $statusPage->show_response_time;
            $this->showIncidentHistory = $statusPage->show_incident_history;
            $this->incidentHistoryDays = $statusPage->incident_history_days;
            $this->autoIncidents = $statusPage->auto_incidents;
            $this->clientId = $statusPage->client_id;
            $this->customDomain = $statusPage->custom_domain ?? '';
            $this->selectedSites = $statusPage->statusPageSites->pluck('site_id')->toArray();
        }
    }

    public function updatedTitle(): void
    {
        if (!$this->statusPage) {
            $this->slug = Str::slug($this->title);
        }
    }

    #[Computed]
    public function availableSites()
    {
        return Site::orderBy('name')->get(['id', 'name', 'url']);
    }

    #[Computed]
    public function clients()
    {
        return Client::orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function incidents()
    {
        if (!$this->statusPage) {
            return collect();
        }

        return $this->statusPage->incidents()
            ->with('updates', 'site')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();
    }

    public function save(): void
    {
        $this->validate([
            'title' => 'required|string|max:255',
            'slug' => 'required|string|max:255|alpha_dash|unique:status_pages,slug' . ($this->statusPage ? ",{$this->statusPage->id}" : ''),
            'description' => 'nullable|string|max:1000',
            'logoUrl' => 'nullable|string|max:500',
            'primaryColor' => 'required|string|max:20',
            'incidentHistoryDays' => 'required|integer|min:1|max:365',
        ]);

        $data = [
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description ?: null,
            'logo_url' => $this->logoUrl ?: null,
            'primary_color' => $this->primaryColor,
            'is_public' => $this->isPublic,
            'show_uptime_percentage' => $this->showUptimePercentage,
            'show_response_time' => $this->showResponseTime,
            'show_incident_history' => $this->showIncidentHistory,
            'incident_history_days' => $this->incidentHistoryDays,
            'auto_incidents' => $this->autoIncidents,
            'client_id' => $this->clientId ?: null,
            'custom_domain' => $this->customDomain ?: null,
        ];

        if ($this->password) {
            $data['password_hash'] = Hash::make($this->password);
        }

        if ($this->statusPage) {
            $this->statusPage->update($data);
        } else {
            $data['user_id'] = auth()->id();
            $this->statusPage = StatusPage::create($data);
        }

        // Sync sites
        $existingSiteIds = $this->statusPage->statusPageSites->pluck('site_id')->toArray();
        $toRemove = array_diff($existingSiteIds, $this->selectedSites);
        $toAdd = array_diff($this->selectedSites, $existingSiteIds);

        if (!empty($toRemove)) {
            $this->statusPage->statusPageSites()->whereIn('site_id', $toRemove)->delete();
        }

        foreach ($toAdd as $index => $siteId) {
            $this->statusPage->statusPageSites()->create([
                'site_id' => $siteId,
                'sort_order' => count($existingSiteIds) + $index,
            ]);
        }

        $this->password = '';
        session()->flash('success', 'Status page saved.');

        if (!$this->statusPage->wasRecentlyCreated) {
            return;
        }

        $this->redirect(route('settings.status-pages.edit', $this->statusPage), navigate: true);
    }

    public function createIncident(): void
    {
        $this->validate([
            'incidentTitle' => 'required|string|max:255',
            'incidentDescription' => 'nullable|string|max:2000',
            'incidentSeverity' => 'required|in:minor,major,critical',
        ]);

        if (!$this->statusPage) return;

        $incident = $this->statusPage->incidents()->create([
            'site_id' => $this->incidentSiteId ?: null,
            'title' => $this->incidentTitle,
            'description' => $this->incidentDescription,
            'severity' => $this->incidentSeverity,
            'status' => 'investigating',
            'started_at' => now(),
        ]);

        $incident->updates()->create([
            'status' => 'investigating',
            'message' => $this->incidentDescription ?: 'We are investigating this issue.',
        ]);

        $this->reset('incidentTitle', 'incidentDescription', 'incidentSeverity', 'incidentSiteId');
        unset($this->incidents);
        session()->flash('success', 'Incident created.');
    }

    public function updateIncidentStatus(int $incidentId, string $status): void
    {
        if (!$this->statusPage) return;
        $incident = $this->statusPage->incidents()->findOrFail($incidentId);
        $incident->update([
            'status' => $status,
            'resolved_at' => $status === 'resolved' ? now() : $incident->resolved_at,
        ]);

        unset($this->incidents);
    }

    public function resolveIncident(int $incidentId): void
    {
        if (!$this->statusPage) return;
        $incident = $this->statusPage->incidents()->findOrFail($incidentId);
        $incident->update([
            'status' => 'resolved',
            'resolved_at' => now(),
        ]);

        $incident->updates()->create([
            'status' => 'resolved',
            'message' => 'This incident has been resolved.',
        ]);

        unset($this->incidents);
        session()->flash('success', 'Incident resolved.');
    }

    public function addIncidentUpdate(int $incidentId): void
    {
        $this->validate([
            'updateMessage' => 'required|string|max:2000',
            'updateStatus' => 'required|in:investigating,identified,monitoring,resolved',
        ]);

        if (!$this->statusPage) return;
        $incident = $this->statusPage->incidents()->findOrFail($incidentId);

        $incident->update([
            'status' => $this->updateStatus,
            'resolved_at' => $this->updateStatus === 'resolved' ? now() : $incident->resolved_at,
        ]);

        $incident->updates()->create([
            'status' => $this->updateStatus,
            'message' => $this->updateMessage,
        ]);

        $this->reset('updateMessage', 'updateStatus');
        unset($this->incidents);
        session()->flash('success', 'Incident update added.');
    }

    #[Computed]
    public function orderedSites()
    {
        if (!$this->statusPage) {
            return collect();
        }

        return $this->statusPage->statusPageSites()->with('site')->orderBy('sort_order')->get();
    }

    public function moveSiteUp(int $siteId): void
    {
        if (!$this->statusPage) return;

        $sites = $this->statusPage->statusPageSites()->orderBy('sort_order')->get();
        $index = $sites->search(fn ($s) => $s->site_id === $siteId);

        if ($index === false || $index === 0) return;

        $current = $sites[$index];
        $previous = $sites[$index - 1];

        $tempOrder = $current->sort_order;
        $current->update(['sort_order' => $previous->sort_order]);
        $previous->update(['sort_order' => $tempOrder]);

        unset($this->orderedSites);
    }

    public function moveSiteDown(int $siteId): void
    {
        if (!$this->statusPage) return;

        $sites = $this->statusPage->statusPageSites()->orderBy('sort_order')->get();
        $index = $sites->search(fn ($s) => $s->site_id === $siteId);

        if ($index === false || $index >= $sites->count() - 1) return;

        $current = $sites[$index];
        $next = $sites[$index + 1];

        $tempOrder = $current->sort_order;
        $current->update(['sort_order' => $next->sort_order]);
        $next->update(['sort_order' => $tempOrder]);

        unset($this->orderedSites);
    }

    public function removePassword(): void
    {
        if ($this->statusPage) {
            $this->statusPage->update(['password_hash' => null]);
            session()->flash('success', 'Password protection removed.');
        }
    }

    public function render()
    {
        return view('livewire.status-pages.status-page-edit')
            ->layout('components.layouts.app', [
                'title' => $this->statusPage ? 'Edit Status Page' : 'Create Status Page',
            ]);
    }
}
