<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use App\Services\SettingsService;
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
        $this->alert_after_failures = $monitor->alert_after_failures ?? 3;
    }

    /**
     * A new monitor starts from the values set in Settings → General →
     * Monitoring Defaults. That section has been writable since it was built
     * and read by nothing, so whatever you typed there had no effect on the
     * next monitor you created. The hard-coded numbers stay as the fallback.
     */
    public function resetFormData(): void
    {
        $settings = app(SettingsService::class);

        // `default_interval` is stored in SECONDS (the select offers 60…3600)
        // while a monitor's interval is in MINUTES. Reading it straight across
        // turned "every 5 minutes" into every 300 minutes.
        $intervalSeconds = (int) ($settings->get('default_interval') ?? 300);

        $this->url = '';
        $this->type = 'http';
        $this->interval_minutes = max(1, intdiv($intervalSeconds, 60));
        $this->timeout = (int) ($settings->get('default_timeout') ?? 30);
        $this->http_method = 'GET';
        $this->follow_redirects = true;
        $this->keyword = '';
        $this->keyword_type = 'exists';
        $this->keyword_case_sensitive = false;
        $this->alert_after_failures = (int) ($settings->get('alert_after_failures') ?? 3);
    }
}
