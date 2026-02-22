<?php

namespace App\Livewire\Uptime;

use App\Jobs\CheckUptime;
use App\Models\Site;
use App\Models\UptimeMonitor;
use Livewire\Attributes\On;
use Livewire\Component;

class ConfigureMonitor extends Component
{
    public ?int $monitorId = null;
    public ?int $siteId = null;

    public string $url = '';
    public string $type = 'http';
    public int $interval_minutes = 5;
    public int $timeout = 30;
    public string $http_method = 'GET';
    public bool $follow_redirects = true;

    // Keyword
    public string $keyword = '';
    public string $keyword_type = 'exists';
    public bool $keyword_case_sensitive = false;

    // SSL
    public bool $check_ssl = true;
    public int $ssl_expiry_threshold = 14;

    // Alerting
    public int $alert_after_failures = 3;

    #[On('open-configure-monitor')]
    public function openModal(?int $monitorId = null, ?int $siteId = null): void
    {
        $this->resetValidation();
        $this->monitorId = $monitorId;
        $this->siteId = $siteId;

        if ($monitorId) {
            $monitor = UptimeMonitor::findOrFail($monitorId);
            $this->siteId = $monitor->site_id;
            $this->url = $monitor->url;
            $this->type = $monitor->type ?? 'http';
            $this->interval_minutes = $monitor->interval_minutes ?? 5;
            $this->timeout = $monitor->timeout ?? 30;
            $this->http_method = $monitor->http_method ?? 'GET';
            $this->follow_redirects = $monitor->follow_redirects ?? true;
            $this->keyword = $monitor->keyword ?? '';
            $this->keyword_type = $monitor->keyword_type ?? 'exists';
            $this->keyword_case_sensitive = $monitor->keyword_case_sensitive ?? false;
            $this->check_ssl = $monitor->check_ssl ?? true;
            $this->ssl_expiry_threshold = $monitor->ssl_expiry_threshold ?? 14;
            $this->alert_after_failures = $monitor->alert_after_failures ?? 3;
        } else {
            $this->resetForm();

            if ($siteId) {
                $site = Site::findOrFail($siteId);
                $this->url = $site->url;
            }
        }

        $this->dispatch('open-modal-configure-monitor');
    }

    protected function resetForm(): void
    {
        $this->url = '';
        $this->type = 'http';
        $this->interval_minutes = 5;
        $this->timeout = 30;
        $this->http_method = 'GET';
        $this->follow_redirects = true;
        $this->keyword = '';
        $this->keyword_type = 'exists';
        $this->keyword_case_sensitive = false;
        $this->check_ssl = true;
        $this->ssl_expiry_threshold = 14;
        $this->alert_after_failures = 3;
    }

    public function save(): void
    {
        $rules = [
            'url' => 'required|url|max:2048',
            'type' => 'required|in:http,keyword,ping',
            'interval_minutes' => 'required|integer|min:1|max:1440',
            'timeout' => 'required|integer|min:5|max:120',
            'http_method' => 'required|in:GET,HEAD,POST',
            'alert_after_failures' => 'required|integer|min:1|max:10',
            'ssl_expiry_threshold' => 'required|integer|min:1|max:90',
        ];

        if ($this->type === 'keyword') {
            $rules['keyword'] = 'required|string|max:500';
            $rules['keyword_type'] = 'required|in:exists,not_exists';
        }

        if (! $this->monitorId) {
            $rules['siteId'] = 'required|exists:sites,id';
        }

        $this->validate($rules);

        $data = [
            'url' => $this->url,
            'type' => $this->type,
            'interval_minutes' => $this->interval_minutes,
            'timeout' => $this->timeout,
            'http_method' => $this->http_method,
            'follow_redirects' => $this->follow_redirects,
            'keyword' => $this->type === 'keyword' ? $this->keyword : null,
            'keyword_type' => $this->type === 'keyword' ? $this->keyword_type : null,
            'keyword_case_sensitive' => $this->type === 'keyword' ? $this->keyword_case_sensitive : false,
            'check_ssl' => $this->check_ssl,
            'ssl_expiry_threshold' => $this->ssl_expiry_threshold,
            'alert_after_failures' => $this->alert_after_failures,
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
