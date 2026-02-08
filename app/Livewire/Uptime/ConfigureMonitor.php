<?php

namespace App\Livewire\Uptime;

use App\Jobs\CheckUptime;
use App\Models\NotificationChannel;
use App\Models\Site;
use App\Models\UptimeMonitor;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class ConfigureMonitor extends Component
{
    #[Locked]
    public ?int $monitorId = null;
    public ?int $siteId = null;

    // Form fields
    public string $type = 'http';
    public string $url = '';
    public int $interval = 300;
    public int $timeout = 30;
    public string $http_method = 'GET';
    public string $http_headers_text = '';
    public string $http_body = '';
    public ?array $accepted_status_codes = null;
    public bool $follow_redirects = true;

    public string $auth_type = 'none';
    public string $auth_username = '';
    public string $auth_password = '';
    public string $auth_token = '';

    public bool $urlAutoFilled = false;

    public string $keyword = '';
    public string $keyword_type = 'exists';
    public bool $keyword_case_sensitive = false;

    public bool $check_ssl = true;
    public int $ssl_expiry_threshold = 14;

    public int $alert_after_failures = 3;
    public array $alert_contacts = [];

    #[On('open-configure-monitor')]
    public function openModal(?int $monitorId = null, ?int $siteId = null): void
    {
        $this->resetValidation();
        $this->monitorId = $monitorId;
        $this->siteId = $siteId;

        if ($monitorId) {
            $monitor = UptimeMonitor::findOrFail($monitorId);
            $this->fillFromMonitor($monitor);
        } else {
            $this->resetForm();

            if ($siteId) {
                $site = Site::findOrFail($siteId);
                $this->url = $site->url;
            }
        }

        $this->dispatch('open-modal-configure-monitor');
    }

    public function updatedSiteId($value): void
    {
        if ($value) {
            $site = Site::find($value);
            if ($site) {
                $this->url = $site->url;
                $this->urlAutoFilled = true;
            }
        }
    }

    protected function fillFromMonitor(UptimeMonitor $monitor): void
    {
        $this->siteId = $monitor->site_id;
        $this->type = $monitor->type;
        $this->url = $monitor->url;
        $this->interval = $monitor->interval;
        $this->timeout = $monitor->timeout;
        $this->http_method = $monitor->http_method;
        $this->http_headers_text = $monitor->http_headers ? json_encode($monitor->http_headers, JSON_PRETTY_PRINT) : '';
        $this->http_body = $monitor->http_body ?? '';
        $this->accepted_status_codes = $monitor->accepted_status_codes;
        $this->follow_redirects = $monitor->follow_redirects;
        $this->auth_type = $monitor->auth_type ?? 'none';
        $this->auth_username = $monitor->auth_username ?? '';
        $this->auth_password = ''; // Don't expose encrypted value
        $this->auth_token = ''; // Don't expose encrypted value
        $this->keyword = $monitor->keyword ?? '';
        $this->keyword_type = $monitor->keyword_type ?? 'exists';
        $this->keyword_case_sensitive = $monitor->keyword_case_sensitive;
        $this->check_ssl = $monitor->check_ssl;
        $this->ssl_expiry_threshold = $monitor->ssl_expiry_threshold;
        $this->alert_after_failures = $monitor->alert_after_failures;
        $this->alert_contacts = $monitor->alert_contacts ?? [];
    }

    protected function resetForm(): void
    {
        $this->type = 'http';
        $this->url = '';
        $this->urlAutoFilled = false;
        $this->interval = 300;
        $this->timeout = 30;
        $this->http_method = 'GET';
        $this->http_headers_text = '';
        $this->http_body = '';
        $this->accepted_status_codes = null;
        $this->follow_redirects = true;
        $this->auth_type = 'none';
        $this->auth_username = '';
        $this->auth_password = '';
        $this->auth_token = '';
        $this->keyword = '';
        $this->keyword_type = 'exists';
        $this->keyword_case_sensitive = false;
        $this->check_ssl = true;
        $this->ssl_expiry_threshold = 14;
        $this->alert_after_failures = 3;
        $this->alert_contacts = [];
    }

    public function save(): void
    {
        $this->validate([
            'siteId' => 'required|exists:sites,id',
            'type' => 'required|in:http,https,keyword,ping',
            'url' => 'required|url',
            'interval' => 'required|integer|min:60|max:3600',
            'timeout' => 'required|integer|min:5|max:120',
            'http_method' => 'required|in:GET,POST,PUT,PATCH,DELETE,HEAD',
            'alert_after_failures' => 'required|integer|min:1|max:10',
        ]);

        $data = [
            'site_id' => $this->siteId,
            'type' => $this->type,
            'url' => $this->url,
            'interval' => $this->interval,
            'timeout' => $this->timeout,
            'http_method' => $this->http_method,
            'http_headers' => $this->http_headers_text ? json_decode($this->http_headers_text, true) : null,
            'http_body' => $this->http_body ?: null,
            'accepted_status_codes' => $this->accepted_status_codes,
            'follow_redirects' => $this->follow_redirects,
            'auth_type' => $this->auth_type === 'none' ? null : $this->auth_type,
            'keyword' => $this->keyword ?: null,
            'keyword_type' => $this->keyword ? $this->keyword_type : null,
            'keyword_case_sensitive' => $this->keyword_case_sensitive,
            'check_ssl' => $this->check_ssl,
            'ssl_expiry_threshold' => $this->ssl_expiry_threshold,
            'alert_after_failures' => $this->alert_after_failures,
            'alert_contacts' => !empty($this->alert_contacts) ? $this->alert_contacts : null,
        ];

        // Only include auth fields if they have values
        if ($this->auth_type === 'basic') {
            $data['auth_username'] = $this->auth_username;
            if ($this->auth_password) {
                $data['auth_password'] = $this->auth_password;
            }
        } elseif ($this->auth_type === 'bearer') {
            if ($this->auth_token) {
                $data['auth_token'] = $this->auth_token;
            }
        }

        if ($this->monitorId) {
            $monitor = UptimeMonitor::findOrFail($this->monitorId);
            $monitor->update($data);
        } else {
            $monitor = UptimeMonitor::create($data);

            // Run the first check synchronously so the user sees results immediately
            CheckUptime::dispatchSync($monitor);
        }

        $this->dispatch('close-modal-configure-monitor');
        $this->dispatch('monitor-saved');
    }

    #[Computed]
    public function channels()
    {
        return NotificationChannel::where('is_active', true)->get();
    }

    #[Computed]
    public function sites()
    {
        return Site::orderBy('name')->get(['id', 'name', 'url']);
    }

    public function render()
    {
        return view('livewire.uptime.configure-monitor');
    }
}
