<?php

declare(strict_types=1);

namespace App\Livewire\Uptime;

use App\Exceptions\SsrfException;
use App\Jobs\CheckUptime;
use App\Livewire\Forms\MonitorFormData;
use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Site;
use App\Models\UptimeMonitor;
use App\Services\Security\SsrfGuard;
use Livewire\Attributes\On;
use Livewire\Component;

class ConfigureMonitor extends Component
{
    use WithSiteAuthorization;

    public ?int $monitorId = null;

    public ?int $siteId = null;

    public MonitorFormData $form;

    #[On('open-configure-monitor')]
    public function openModal(?int $monitorId = null, ?int $siteId = null): void
    {
        $this->resetValidation();
        $this->monitorId = $monitorId;
        $this->siteId = $siteId;

        if ($monitorId) {
            $monitor = UptimeMonitor::findOrFail($monitorId);
            $this->siteId = $monitor->site_id;
            $this->form->setFromMonitor($monitor);
        } else {
            $this->form->resetFormData();

            if ($siteId) {
                $site = Site::findOrFail($siteId);
                $this->form->url = $site->url;
            }
        }

        $this->dispatch('open-modal-configure-monitor');
    }

    public function save(): void
    {
        $this->form->validate();

        // SSRF guard: the monitored URL is user-supplied and probed
        // server-side. A legitimate public client domain passes; an internal /
        // loopback / metadata target is rejected here at save time (the probe
        // itself stays untouched so Cloudflare-challenge handling is preserved).
        try {
            app(SsrfGuard::class)->assertPublicUrl($this->form->url);
        } catch (SsrfException) {
            $this->addError('form.url', 'This URL cannot be monitored — it points to a private or internal address.');

            return;
        }

        if (! $this->monitorId) {
            $this->validate([
                'siteId' => 'required|exists:sites,id',
            ]);
        }

        $site = $this->monitorId
            ? UptimeMonitor::findOrFail($this->monitorId)->site
            : Site::findOrFail($this->siteId);
        $this->authorizeSiteModification($site);

        $data = [
            'url' => $this->form->url,
            'type' => $this->form->type,
            'interval_minutes' => $this->form->interval_minutes,
            'timeout' => $this->form->timeout,
            'http_method' => $this->form->http_method,
            'follow_redirects' => $this->form->follow_redirects,
            'keyword' => $this->form->type === 'keyword' ? $this->form->keyword : null,
            'keyword_type' => $this->form->type === 'keyword' ? $this->form->keyword_type : null,
            'keyword_case_sensitive' => $this->form->type === 'keyword' ? $this->form->keyword_case_sensitive : false,
            'alert_after_failures' => $this->form->alert_after_failures,
        ];

        if ($this->monitorId) {
            $monitor = UptimeMonitor::findOrFail($this->monitorId);
            $monitor->update($data);
        } else {
            $data['site_id'] = $this->siteId;
            $data['next_check_at'] = now();
            $monitor = UptimeMonitor::create($data);
            CheckUptime::dispatch($monitor);
        }

        $this->dispatch('close-modal-configure-monitor');
        $this->dispatch('monitor-saved');
    }

    public function render()
    {
        return view('livewire.uptime.configure-monitor');
    }
}
