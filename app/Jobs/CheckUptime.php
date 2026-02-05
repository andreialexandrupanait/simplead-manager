<?php

namespace App\Jobs;

use App\Models\UptimeCheck;
use App\Models\UptimeIncident;
use App\Models\UptimeMonitor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\ActivityLogger;
use App\Services\MaintenanceService;
use Illuminate\Support\Facades\Http;

class CheckUptime implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public UptimeMonitor $monitor
    ) {}

    public function handle(): void
    {
        if (MaintenanceService::isSiteInMaintenance($this->monitor->site, 'uptime')) {
            $this->monitor->update(['next_check_at' => now()->addSeconds($this->monitor->interval)]);
            return;
        }

        $result = $this->performCheck();

        $check = $this->saveCheck($result);

        $this->updateMonitorState($result);

        $this->updateUptimeStats();

        if (!$result['is_up']) {
            $this->handleFailure($result);
        } else {
            $this->handleRecovery();
        }

        // Sync to site
        $this->monitor->site->update([
            'is_up' => $this->monitor->current_state === 'up',
            'uptime_percentage' => $this->monitor->uptime_30d,
        ]);
    }

    protected function performCheck(): array
    {
        $startTime = microtime(true);
        $result = [
            'is_up' => false,
            'response_time' => null,
            'status_code' => null,
            'failure_reason' => null,
            'keyword_found' => null,
            'ssl_expires_at' => null,
        ];

        try {
            $options = [
                'timeout' => $this->monitor->timeout,
                'connect_timeout' => $this->monitor->timeout,
            ];

            // Build the HTTP request
            $request = Http::timeout($this->monitor->timeout)
                ->connectTimeout($this->monitor->timeout);

            // Follow redirects
            if (!$this->monitor->follow_redirects) {
                $request = $request->withOptions(['allow_redirects' => false]);
            }

            // Auth
            if ($this->monitor->auth_type === 'basic') {
                $request = $request->withBasicAuth(
                    $this->monitor->auth_username ?? '',
                    $this->monitor->auth_password ?? ''
                );
            } elseif ($this->monitor->auth_type === 'bearer') {
                $request = $request->withToken($this->monitor->auth_token ?? '');
            }

            // Custom headers
            if ($this->monitor->http_headers) {
                $request = $request->withHeaders($this->monitor->http_headers);
            }

            // Make the request
            $method = strtolower($this->monitor->http_method);
            $response = $this->monitor->http_body
                ? $request->$method($this->monitor->url, $this->monitor->http_body)
                : $request->$method($this->monitor->url);

            $responseTime = (int) round((microtime(true) - $startTime) * 1000);
            $result['response_time'] = $responseTime;
            $result['status_code'] = $response->status();

            // Check status code
            $acceptedCodes = $this->monitor->accepted_status_codes ?? [200, 201, 202, 203, 204, 301, 302];
            $result['is_up'] = in_array($response->status(), $acceptedCodes);

            if (!$result['is_up']) {
                $result['failure_reason'] = "HTTP {$response->status()}";
            }

            // Keyword checking
            if ($this->monitor->keyword && $result['is_up']) {
                $body = $response->body();
                $keyword = $this->monitor->keyword;

                $found = $this->monitor->keyword_case_sensitive
                    ? str_contains($body, $keyword)
                    : str_contains(strtolower($body), strtolower($keyword));

                $result['keyword_found'] = $found;

                if ($this->monitor->keyword_type === 'exists' && !$found) {
                    $result['is_up'] = false;
                    $result['failure_reason'] = 'Keyword not found';
                } elseif ($this->monitor->keyword_type === 'not_exists' && $found) {
                    $result['is_up'] = false;
                    $result['failure_reason'] = 'Unwanted keyword found';
                }
            }

            // SSL check
            if ($this->monitor->check_ssl && str_starts_with($this->monitor->url, 'https://')) {
                $result['ssl_expires_at'] = $this->checkSslExpiry();
            }

        } catch (\Exception $e) {
            $result['response_time'] = (int) round((microtime(true) - $startTime) * 1000);
            $result['failure_reason'] = $this->sanitizeErrorMessage($e->getMessage());
        }

        return $result;
    }

    protected function checkSslExpiry(): ?\Carbon\Carbon
    {
        try {
            $parsed = parse_url($this->monitor->url);
            $host = $parsed['host'] ?? '';
            $port = $parsed['port'] ?? 443;

            $context = stream_context_create([
                'ssl' => [
                    'capture_peer_cert' => true,
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);

            $client = @stream_socket_client(
                "ssl://{$host}:{$port}",
                $errno,
                $errstr,
                10,
                STREAM_CLIENT_CONNECT,
                $context
            );

            if (!$client) {
                return null;
            }

            $params = stream_context_get_params($client);
            $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate'] ?? '');
            fclose($client);

            if ($cert && isset($cert['validTo_time_t'])) {
                return \Carbon\Carbon::createFromTimestamp($cert['validTo_time_t']);
            }
        } catch (\Exception $e) {
            // SSL check is non-critical
        }

        return null;
    }

    protected function saveCheck(array $result): UptimeCheck
    {
        return $this->monitor->checks()->create([
            'is_up' => $result['is_up'],
            'response_time' => $result['response_time'],
            'status_code' => $result['status_code'],
            'failure_reason' => $result['failure_reason'],
            'keyword_found' => $result['keyword_found'],
            'ssl_expires_at' => $result['ssl_expires_at'],
            'checked_at' => now(),
        ]);
    }

    protected function updateMonitorState(array $result): void
    {
        $previousState = $this->monitor->current_state;

        if ($result['is_up']) {
            $this->monitor->consecutive_failures = 0;
            $this->monitor->current_state = 'up';
        } else {
            $this->monitor->consecutive_failures++;

            if ($this->monitor->consecutive_failures >= $this->monitor->alert_after_failures) {
                $this->monitor->current_state = 'down';
            } else {
                $this->monitor->current_state = 'degraded';
            }
        }

        if ($previousState !== $this->monitor->current_state) {
            $this->monitor->last_state_change_at = now();
        }

        $this->monitor->last_checked_at = now();
        $this->monitor->next_check_at = now()->addSeconds($this->monitor->interval);
        $this->monitor->last_response_time = $result['response_time'];
        $this->monitor->last_failure_reason = $result['failure_reason'];
        $this->monitor->save();
    }

    protected function updateUptimeStats(): void
    {
        $monitor = $this->monitor;

        // Calculate uptime percentages for various periods
        $periods = [
            '24h' => now()->subHours(24),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '365d' => now()->subDays(365),
        ];

        foreach ($periods as $key => $since) {
            $total = $monitor->checks()->where('checked_at', '>=', $since)->count();
            $up = $monitor->checks()->where('checked_at', '>=', $since)->where('is_up', true)->count();

            $monitor->{"uptime_{$key}"} = $total > 0 ? round(($up / $total) * 100, 3) : null;
        }

        // Average response time (last 24h, only successful checks)
        $monitor->avg_response_time = (int) $monitor->checks()
            ->where('checked_at', '>=', now()->subHours(24))
            ->where('is_up', true)
            ->whereNotNull('response_time')
            ->avg('response_time');

        $monitor->save();
    }

    protected function handleFailure(array $result): void
    {
        $incident = $this->monitor->ongoingIncident;

        if (!$incident) {
            $incident = $this->monitor->incidents()->create([
                'status' => 'ongoing',
                'cause' => $result['failure_reason'],
                'started_at' => now(),
            ]);
        }

        // Only notify when threshold is reached
        if ($this->monitor->consecutive_failures === $this->monitor->alert_after_failures) {
            NotifyIncident::dispatch($incident, 'down');
            ActivityLogger::siteDown($this->monitor->site, $result['failure_reason'] ?? 'Unknown');
            CreateStatusPageIncident::dispatch($this->monitor->site, $result['failure_reason'] ?? 'Site is down');
        }
    }

    protected function handleRecovery(): void
    {
        $incident = $this->monitor->ongoingIncident;

        if ($incident) {
            $incident->update([
                'status' => 'resolved',
                'resolved_at' => now(),
            ]);

            NotifyIncident::dispatch($incident->fresh(), 'recovery');
            ResolveStatusPageIncident::dispatch($this->monitor->site);

            $downtimeMinutes = $incident->started_at ? (int) $incident->started_at->diffInMinutes(now()) : 0;
            ActivityLogger::siteRecovered($this->monitor->site, $downtimeMinutes);
        }
    }

    protected function sanitizeErrorMessage(string $message): string
    {
        // Remove file paths and sensitive info from error messages
        $message = preg_replace('/\/[^\s]+/', '[path]', $message);
        return \Illuminate\Support\Str::limit($message, 250);
    }
}
