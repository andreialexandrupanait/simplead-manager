<?php

namespace App\Livewire\Forms;

use Livewire\Attributes\Validate;
use Livewire\Form;

class MonitorFormData extends Form
{
    #[Validate('required|url|max:2048')]
    public string $url = '';

    #[Validate('required|in:http,keyword,ping')]
    public string $type = 'http';

    #[Validate('required|integer|min:1|max:1440')]
    public int $interval_minutes = 5;

    #[Validate('required|integer|min:5|max:120')]
    public int $timeout = 30;

    #[Validate('required|in:GET,HEAD,POST')]
    public string $http_method = 'GET';

    public bool $follow_redirects = true;

    // Keyword
    public string $keyword = '';

    public string $keyword_type = 'exists';

    public bool $keyword_case_sensitive = false;

    // SSL
    public bool $check_ssl = true;

    #[Validate('required|integer|min:1|max:90')]
    public int $ssl_expiry_threshold = 14;

    // Alerting
    #[Validate('required|integer|min:1|max:10')]
    public int $alert_after_failures = 3;

    /**
     * Dynamic validation rules -- keyword fields required only for keyword type.
     */
    public function rules(): array
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

        return $rules;
    }

    public function setFromMonitor($monitor): void
    {
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
    }

    public function resetFormData(): void
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
}
