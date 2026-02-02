# SimpleAd Manager — Feature Spec: Uptime Monitoring + Application Settings

---

## PART 1: UPTIME MONITORING

### 1.1 Overview

Full uptime monitoring system inspired by UptimeRobot. Each site can have an uptime monitor that checks availability at configurable intervals. The system tracks response times, detects incidents, calculates uptime percentages, and sends notifications.

---

### 1.2 Database Schema

#### Migration: `uptime_monitors`

```php
Schema::create('uptime_monitors', function (Blueprint $table) {
    $table->id();
    $table->foreignId('site_id')->constrained()->onDelete('cascade');
    
    // Monitor configuration
    $table->string('type')->default('http'); // http, https, ping, port, keyword
    $table->string('url');
    $table->integer('interval')->default(300); // check interval in seconds (60, 120, 180, 300, 600, 900, 1800, 3600)
    $table->integer('timeout')->default(30); // timeout in seconds
    
    // HTTP settings
    $table->string('http_method')->default('GET'); // GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS
    $table->json('http_headers')->nullable(); // custom headers as key-value pairs
    $table->text('http_body')->nullable(); // request body for POST/PUT
    $table->json('accepted_status_codes')->default('[200, 201, 301, 302]'); // expected HTTP status codes
    $table->boolean('follow_redirects')->default(true);
    
    // Authentication
    $table->string('auth_type')->nullable(); // null, basic, bearer, header
    $table->string('auth_username')->nullable();
    $table->text('auth_password')->nullable(); // encrypted
    $table->text('auth_token')->nullable(); // encrypted
    
    // Keyword monitoring
    $table->boolean('keyword_enabled')->default(false);
    $table->string('keyword_type')->nullable(); // exists, not_exists
    $table->string('keyword_value')->nullable();
    $table->boolean('keyword_case_sensitive')->default(false);
    
    // Port monitoring (for type=port)
    $table->integer('port')->nullable();
    
    // SSL monitoring (bonus — check SSL alongside uptime)
    $table->boolean('ssl_check_enabled')->default(true);
    $table->integer('ssl_expiry_threshold')->default(30); // alert X days before expiry
    
    // Alert configuration
    $table->integer('alert_after_failures')->default(3); // alert after X consecutive failures
    $table->json('alert_contacts')->nullable(); // notification channel IDs or emails
    
    // State
    $table->string('status')->default('active'); // active, paused
    $table->string('current_state')->default('unknown'); // up, down, degraded, unknown
    $table->timestamp('last_checked_at')->nullable();
    $table->timestamp('last_up_at')->nullable();
    $table->timestamp('last_down_at')->nullable();
    $table->integer('consecutive_failures')->default(0);
    
    // Cached stats (updated after each check)
    $table->decimal('uptime_24h', 6, 3)->nullable();
    $table->decimal('uptime_7d', 6, 3)->nullable();
    $table->decimal('uptime_30d', 6, 3)->nullable();
    $table->decimal('uptime_365d', 6, 3)->nullable();
    $table->integer('avg_response_time')->nullable(); // ms, last 24h average
    
    $table->timestamps();
    
    $table->index(['site_id']);
    $table->index(['status', 'current_state']);
    $table->index(['last_checked_at']);
});
```

#### Migration: `uptime_checks`

```php
Schema::create('uptime_checks', function (Blueprint $table) {
    $table->id();
    $table->foreignId('monitor_id')->constrained('uptime_monitors')->onDelete('cascade');
    
    $table->boolean('is_up');
    $table->integer('status_code')->nullable();
    $table->integer('response_time')->nullable(); // milliseconds
    $table->integer('response_size')->nullable(); // bytes
    $table->string('ip_address')->nullable(); // resolved IP
    
    // Error details
    $table->string('error_type')->nullable(); // timeout, dns_error, connection_refused, ssl_error, keyword_missing, etc.
    $table->text('error_message')->nullable();
    
    // SSL info captured during check
    $table->string('ssl_issuer')->nullable();
    $table->timestamp('ssl_expires_at')->nullable();
    $table->integer('ssl_days_remaining')->nullable();
    
    // Keyword check result
    $table->boolean('keyword_found')->nullable();
    
    $table->string('region')->default('eu-central'); // check origin region
    
    $table->timestamp('checked_at');
    
    $table->index(['monitor_id', 'checked_at']);
    $table->index(['monitor_id', 'is_up']);
});
```

#### Migration: `uptime_incidents`

```php
Schema::create('uptime_incidents', function (Blueprint $table) {
    $table->id();
    $table->foreignId('monitor_id')->constrained('uptime_monitors')->onDelete('cascade');
    
    $table->string('status')->default('ongoing'); // ongoing, resolved
    $table->string('cause')->nullable(); // timeout, 500_error, dns_error, ssl_error, keyword_missing, connection_refused
    $table->text('cause_detail')->nullable();
    
    $table->integer('status_code')->nullable();
    $table->integer('checks_failed')->default(0); // how many checks failed during this incident
    
    $table->timestamp('started_at');
    $table->timestamp('resolved_at')->nullable();
    $table->integer('duration_seconds')->nullable();
    
    // Notification tracking
    $table->timestamp('notified_at')->nullable();
    $table->json('notified_via')->nullable(); // ["email", "slack"]
    
    $table->timestamps();
    
    $table->index(['monitor_id', 'status']);
    $table->index(['monitor_id', 'started_at']);
});
```

---

### 1.3 Models

#### UptimeMonitor

```php
// app/Models/UptimeMonitor.php

class UptimeMonitor extends Model
{
    protected $casts = [
        'http_headers' => 'array',
        'accepted_status_codes' => 'array',
        'alert_contacts' => 'array',
        'keyword_enabled' => 'boolean',
        'keyword_case_sensitive' => 'boolean',
        'follow_redirects' => 'boolean',
        'ssl_check_enabled' => 'boolean',
        'last_checked_at' => 'datetime',
        'last_up_at' => 'datetime',
        'last_down_at' => 'datetime',
    ];

    protected $encrypted = ['auth_password', 'auth_token'];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function checks(): HasMany
    {
        return $this->hasMany(UptimeCheck::class, 'monitor_id');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(UptimeIncident::class, 'monitor_id');
    }

    public function latestCheck(): HasOne
    {
        return $this->hasOne(UptimeCheck::class, 'monitor_id')->latestOfMany('checked_at');
    }

    public function ongoingIncident(): HasOne
    {
        return $this->hasOne(UptimeIncident::class, 'monitor_id')->where('status', 'ongoing');
    }

    // Scopes
    public function scopeActive($query) { return $query->where('status', 'active'); }
    public function scopeDue($query) {
        return $query->active()
            ->where(function ($q) {
                $q->whereNull('last_checked_at')
                  ->orWhereRaw('last_checked_at <= NOW() - (interval || \' seconds\')::interval');
            });
    }
}
```

#### UptimeCheck

```php
class UptimeCheck extends Model
{
    public $timestamps = false;
    
    protected $casts = [
        'is_up' => 'boolean',
        'keyword_found' => 'boolean',
        'checked_at' => 'datetime',
        'ssl_expires_at' => 'datetime',
    ];

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(UptimeMonitor::class, 'monitor_id');
    }
}
```

#### UptimeIncident

```php
class UptimeIncident extends Model
{
    protected $casts = [
        'notified_via' => 'array',
        'started_at' => 'datetime',
        'resolved_at' => 'datetime',
        'notified_at' => 'datetime',
    ];

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(UptimeMonitor::class, 'monitor_id');
    }

    public function getDurationAttribute(): ?string
    {
        if (!$this->resolved_at) {
            $seconds = now()->diffInSeconds($this->started_at);
        } else {
            $seconds = $this->duration_seconds;
        }
        
        if ($seconds < 60) return $seconds . 's';
        if ($seconds < 3600) return floor($seconds / 60) . 'm';
        if ($seconds < 86400) return floor($seconds / 3600) . 'h ' . floor(($seconds % 3600) / 60) . 'm';
        return floor($seconds / 86400) . 'd ' . floor(($seconds % 86400) / 3600) . 'h';
    }
}
```

---

### 1.4 Background Job: Uptime Checker

```php
// app/Jobs/CheckUptime.php

class CheckUptime implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public UptimeMonitor $monitor) {}

    public function handle(): void
    {
        $result = $this->performCheck();
        
        // Save check record
        $check = $this->monitor->checks()->create([
            'is_up' => $result['is_up'],
            'status_code' => $result['status_code'],
            'response_time' => $result['response_time'],
            'response_size' => $result['response_size'],
            'ip_address' => $result['ip_address'],
            'error_type' => $result['error_type'],
            'error_message' => $result['error_message'],
            'ssl_issuer' => $result['ssl_issuer'],
            'ssl_expires_at' => $result['ssl_expires_at'],
            'ssl_days_remaining' => $result['ssl_days_remaining'],
            'keyword_found' => $result['keyword_found'],
            'region' => config('uptime.region', 'eu-central'),
            'checked_at' => now(),
        ]);

        // Update monitor state
        $this->updateMonitorState($result['is_up']);
        
        // Update cached uptime stats
        $this->updateUptimeStats();

        // Handle incidents
        if (!$result['is_up']) {
            $this->handleFailure($result);
        } else {
            $this->handleRecovery();
        }
    }

    private function performCheck(): array
    {
        $result = [
            'is_up' => false,
            'status_code' => null,
            'response_time' => null,
            'response_size' => null,
            'ip_address' => null,
            'error_type' => null,
            'error_message' => null,
            'ssl_issuer' => null,
            'ssl_expires_at' => null,
            'ssl_days_remaining' => null,
            'keyword_found' => null,
        ];

        try {
            $startTime = microtime(true);

            // Build HTTP request
            $options = [
                'timeout' => $this->monitor->timeout,
                'allow_redirects' => $this->monitor->follow_redirects,
                'verify' => true, // SSL verification
                'http_errors' => false, // Don't throw on 4xx/5xx
            ];

            // Custom headers
            if ($this->monitor->http_headers) {
                $options['headers'] = $this->monitor->http_headers;
            }

            // Authentication
            if ($this->monitor->auth_type === 'basic') {
                $options['auth'] = [$this->monitor->auth_username, $this->monitor->auth_password];
            } elseif ($this->monitor->auth_type === 'bearer') {
                $options['headers']['Authorization'] = 'Bearer ' . $this->monitor->auth_token;
            }

            // Request body
            if ($this->monitor->http_body && in_array($this->monitor->http_method, ['POST', 'PUT', 'PATCH'])) {
                $options['body'] = $this->monitor->http_body;
            }

            $client = new \GuzzleHttp\Client();
            $response = $client->request($this->monitor->http_method, $this->monitor->url, $options);

            $endTime = microtime(true);

            $result['status_code'] = $response->getStatusCode();
            $result['response_time'] = (int)(($endTime - $startTime) * 1000);
            $result['response_size'] = strlen($response->getBody()->getContents());
            
            // Resolve IP
            $host = parse_url($this->monitor->url, PHP_URL_HOST);
            $result['ip_address'] = gethostbyname($host);

            // Check status code
            $acceptedCodes = $this->monitor->accepted_status_codes ?? [200, 201, 301, 302];
            $statusOk = in_array($result['status_code'], $acceptedCodes);

            // Keyword check
            if ($this->monitor->keyword_enabled && $this->monitor->keyword_value) {
                $body = (string) $response->getBody();
                $keyword = $this->monitor->keyword_value;
                
                if ($this->monitor->keyword_case_sensitive) {
                    $found = str_contains($body, $keyword);
                } else {
                    $found = str_contains(strtolower($body), strtolower($keyword));
                }

                $result['keyword_found'] = $found;

                if ($this->monitor->keyword_type === 'exists' && !$found) {
                    $statusOk = false;
                    $result['error_type'] = 'keyword_missing';
                    $result['error_message'] = "Keyword '{$keyword}' not found on page";
                } elseif ($this->monitor->keyword_type === 'not_exists' && $found) {
                    $statusOk = false;
                    $result['error_type'] = 'keyword_found';
                    $result['error_message'] = "Keyword '{$keyword}' was found on page (should not exist)";
                }
            }

            // SSL check
            if ($this->monitor->ssl_check_enabled && str_starts_with($this->monitor->url, 'https')) {
                $sslInfo = $this->getSSLInfo($host);
                $result['ssl_issuer'] = $sslInfo['issuer'];
                $result['ssl_expires_at'] = $sslInfo['expires_at'];
                $result['ssl_days_remaining'] = $sslInfo['days_remaining'];
            }

            $result['is_up'] = $statusOk;

            if (!$statusOk && !$result['error_type']) {
                $result['error_type'] = 'unexpected_status';
                $result['error_message'] = "Got status {$result['status_code']}, expected one of: " . implode(', ', $acceptedCodes);
            }

        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            $result['error_type'] = str_contains($e->getMessage(), 'timed out') ? 'timeout' : 'connection_refused';
            $result['error_message'] = $e->getMessage();
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $result['error_type'] = 'request_error';
            $result['error_message'] = $e->getMessage();
        } catch (\Exception $e) {
            $result['error_type'] = 'unknown_error';
            $result['error_message'] = $e->getMessage();
        }

        return $result;
    }

    private function getSSLInfo(string $host): array
    {
        try {
            $context = stream_context_create(['ssl' => ['capture_peer_cert' => true]]);
            $stream = @stream_socket_client("ssl://{$host}:443", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context);
            
            if (!$stream) return ['issuer' => null, 'expires_at' => null, 'days_remaining' => null];

            $params = stream_context_get_params($stream);
            $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
            fclose($stream);

            $expiresAt = Carbon::createFromTimestamp($cert['validTo_time_t']);
            
            return [
                'issuer' => $cert['issuer']['O'] ?? $cert['issuer']['CN'] ?? null,
                'expires_at' => $expiresAt,
                'days_remaining' => (int) now()->diffInDays($expiresAt, false),
            ];
        } catch (\Exception $e) {
            return ['issuer' => null, 'expires_at' => null, 'days_remaining' => null];
        }
    }

    private function updateMonitorState(bool $isUp): void
    {
        $data = ['last_checked_at' => now()];
        
        if ($isUp) {
            $data['current_state'] = 'up';
            $data['last_up_at'] = now();
            $data['consecutive_failures'] = 0;
        } else {
            $data['consecutive_failures'] = $this->monitor->consecutive_failures + 1;
            
            if ($data['consecutive_failures'] >= $this->monitor->alert_after_failures) {
                $data['current_state'] = 'down';
                $data['last_down_at'] = now();
            } else {
                $data['current_state'] = 'degraded';
            }
        }
        
        $this->monitor->update($data);
    }

    private function updateUptimeStats(): void
    {
        $monitorId = $this->monitor->id;
        
        $stats = [];
        foreach (['24h' => 1, '7d' => 7, '30d' => 30, '365d' => 365] as $key => $days) {
            $total = UptimeCheck::where('monitor_id', $monitorId)
                ->where('checked_at', '>=', now()->subDays($days))
                ->count();
            $up = UptimeCheck::where('monitor_id', $monitorId)
                ->where('checked_at', '>=', now()->subDays($days))
                ->where('is_up', true)
                ->count();
            
            $stats["uptime_{$key}"] = $total > 0 ? round(($up / $total) * 100, 3) : null;
        }

        // Average response time (last 24h, only successful checks)
        $stats['avg_response_time'] = (int) UptimeCheck::where('monitor_id', $monitorId)
            ->where('checked_at', '>=', now()->subDay())
            ->where('is_up', true)
            ->avg('response_time');

        $this->monitor->update($stats);
    }

    private function handleFailure(array $result): void
    {
        $monitor = $this->monitor->fresh();
        
        // Create or update incident
        $incident = $monitor->ongoingIncident;
        
        if (!$incident) {
            $incident = $monitor->incidents()->create([
                'status' => 'ongoing',
                'cause' => $result['error_type'],
                'cause_detail' => $result['error_message'],
                'status_code' => $result['status_code'],
                'checks_failed' => 1,
                'started_at' => now(),
            ]);
        } else {
            $incident->increment('checks_failed');
        }

        // Send notification after threshold
        if ($monitor->consecutive_failures === $monitor->alert_after_failures && !$incident->notified_at) {
            // Dispatch notification job (implemented in Settings section)
            NotifyIncident::dispatch($incident, 'down');
            $incident->update(['notified_at' => now()]);
        }
    }

    private function handleRecovery(): void
    {
        $incident = $this->monitor->ongoingIncident;
        
        if ($incident) {
            $incident->update([
                'status' => 'resolved',
                'resolved_at' => now(),
                'duration_seconds' => now()->diffInSeconds($incident->started_at),
            ]);

            // Send recovery notification
            if ($incident->notified_at) {
                NotifyIncident::dispatch($incident, 'recovery');
            }
        }
    }
}
```

---

### 1.5 Scheduler

```php
// app/Console/Kernel.php or bootstrap/app.php (Laravel 11)

// Dispatch checks for all due monitors every minute
Schedule::call(function () {
    UptimeMonitor::active()
        ->where(function ($q) {
            $q->whereNull('last_checked_at')
              ->orWhereRaw("last_checked_at <= NOW() - (interval || ' seconds') * INTERVAL '1 second'");
        })
        ->each(function ($monitor) {
            CheckUptime::dispatch($monitor);
        });
})->everyMinute();

// Clean old checks (keep 90 days)
Schedule::command('model:prune', ['--model' => UptimeCheck::class])->daily();
```

---

### 1.6 UI Pages

#### 1.6.1 Global Uptime Page (`/uptime`)

This is the main uptime overview accessible from the global sidebar.

```
┌─────────────────────────────────────────────────────────────────────┐
│  Uptime Monitoring                                    [+ Add Monitor] │
├─────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐                │
│  │  12 Up   │ │  0 Down  │ │  1 Degraded │ │  2 Paused │             │
│  │  ● green │ │  ● red   │ │  ● yellow │ │  ● gray  │               │
│  └──────────┘ └──────────┘ └──────────┘ └──────────┘                │
│                                                                       │
│  [All] [Up] [Down] [Degraded] [Paused]        🔍 Search monitors...  │
│                                                                       │
│  ┌─────────────────────────────────────────────────────────────────┐ │
│  │  ● simplead.ro              100%    196ms    Last: 2m ago      │ │
│  │    https://simplead.ro      ████████████████████████ (24h bar) │ │
│  ├─────────────────────────────────────────────────────────────────┤ │
│  │  ● client-site.com          99.95%  340ms    Last: 1m ago      │ │
│  │    https://client-site.com  ████████████████████░███ (24h bar) │ │
│  ├─────────────────────────────────────────────────────────────────┤ │
│  │  ○ offline-site.ro          95.2%   ---      Last: 5m ago      │ │
│  │    https://offline-site.ro  ████████████████░░░░░░░░ (24h bar) │ │
│  └─────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────┘
```

**Components needed:**
- Summary stats bar (Up / Down / Degraded / Paused counts)
- Filter tabs
- Search input (wire:model.live with debounce)
- Monitor row with: status dot, site name + URL, uptime %, response time, last check time, 24h uptime bar
- 24h uptime bar: thin horizontal bar divided into segments (green=up, red=down, gray=no data)

#### 1.6.2 Site-Context Uptime Page (`/sites/{site}/uptime`)

Same data but filtered for a specific site. Shows the full monitor detail inline:

```
┌─────────────────────────────────────────────────────────────────────┐
│  Uptime — simplead.ro                         [Pause] [Edit] [Test] │
├─────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  ┌─ Current Status ──┐  ┌─ Last Check ────────┐  ┌─ 24h ──────────┐ │
│  │  ● Up             │  │  2m 15s ago         │  │  100%           │ │
│  │  Up for 3d 14h    │  │  Every 5 minutes    │  │  0 incidents    │ │
│  │                   │  │  Status: 200        │  │  0m downtime    │ │
│  └───────────────────┘  └─────────────────────┘  └────────────────┘ │
│                                                                       │
│  ┌─ 7 days ──────────┐  ┌─ 30 days ──────────┐  ┌─ 365 days ─────┐ │
│  │  100%             │  │  99.95%            │  │  99.8%          │ │
│  │  0 incidents      │  │  1 incident        │  │  3 incidents    │ │
│  │  0m down          │  │  24m down          │  │  2h 15m down    │ │
│  └───────────────────┘  └────────────────────┘  └────────────────┘ │
│                                                                       │
│  ┌─ Response Time ─────────────────────────────────────────────────┐ │
│  │  [1h] [24h] [7d] [30d]                          Avg: 196ms    │ │
│  │                                                                  │ │
│  │   400ms ─┐                                                       │ │
│  │          │       ╭──────╮                                        │ │
│  │   200ms ─┤  ─────╯      ╰──────────────────                     │ │
│  │          │                                                       │ │
│  │     0ms ─┴─────────────────────────────────────────────────────  │ │
│  │                                                                  │ │
│  │  Response time stats:                                            │ │
│  │  Average: 196ms  |  Min: 89ms  |  Max: 412ms  |  P95: 380ms   │ │
│  └──────────────────────────────────────────────────────────────────┘ │
│                                                                       │
│  ┌─ Latest Incidents ──────────────────────────────────────────────┐ │
│  │  Status    │ Cause              │ Started          │ Duration   │ │
│  │ ─────────────────────────────────────────────────────────────── │ │
│  │  Resolved  │ Timeout            │ Jan 28, 14:30    │ 24m        │ │
│  │  Resolved  │ 500 Internal Error │ Jan 15, 03:12    │ 8m         │ │
│  │  Resolved  │ Connection Refused │ Dec 22, 18:45    │ 1h 43m     │ │
│  └──────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────┘
```

**Components needed:**
- Status cards grid (Current Status, Last Check, 24h/7d/30d/365d stats)
- Response time line chart (ApexCharts or Chart.js) with time range toggle
- Response time stats bar (avg, min, max, p95)
- Incidents table with status badge, cause, started time, duration
- Action buttons: Pause/Resume, Edit monitor settings, Test now (trigger manual check)

#### 1.6.3 Add/Edit Monitor Page or Modal

When adding uptime monitoring to a site (from site settings or "Add Monitor" button):

```
┌─────────────────────────────────────────────────────────────────────┐
│  Configure Uptime Monitor                                    [Save] │
├─────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  Monitor Type                                                        │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐ │
│  │ ● HTTP(s) │ │  Ping   │ │  Port   │ │ Keyword  │ │          │ │
│  └──────────┘ └──────────┘ └──────────┘ └──────────┘ └──────────┘ │
│                                                                       │
│  URL                                                                  │
│  [ https://simplead.ro                                             ] │
│                                                                       │
│  Check Interval                                                      │
│  [ Every 5 minutes ▼ ]                                               │
│                                                                       │
│  ─── Advanced Settings ──────────────────────────────────────────── │
│                                                                       │
│  HTTP Method        Timeout           Follow Redirects               │
│  [ GET ▼ ]          [ 30 seconds ▼ ]  [ ✓ On ]                      │
│                                                                       │
│  Accepted Status Codes                                               │
│  [ 200 ] [ 201 ] [ 301 ] [ 302 ] [+ Add]                           │
│                                                                       │
│  ─── Authentication ─────────────────────────────────────────────── │
│                                                                       │
│  Auth Type: [ None ▼ ]                                               │
│  (shows username/password fields for Basic, token field for Bearer)  │
│                                                                       │
│  ─── Keyword Monitoring ─────────────────────────────────────────── │
│                                                                       │
│  [ ] Enable keyword monitoring                                       │
│  Type: [ Keyword exists ▼ ]                                          │
│  Keyword: [ _________________________ ]                              │
│  [ ] Case sensitive                                                  │
│                                                                       │
│  ─── Custom Headers ─────────────────────────────────────────────── │
│                                                                       │
│  Key: [ _____________ ]  Value: [ _____________ ]  [+ Add Header]   │
│                                                                       │
│  ─── Alerts ─────────────────────────────────────────────────────── │
│                                                                       │
│  Alert after [ 3 ▼ ] consecutive failures                            │
│  Notification contacts: [ Default (from settings) ▼ ]                │
│                                                                       │
│                                           [Cancel]  [Save Monitor]   │
└─────────────────────────────────────────────────────────────────────┘
```

#### 1.6.4 24-Hour Uptime Bar Component

A thin horizontal bar showing uptime over the last 24 hours. Each segment represents a time slice (e.g., 15 minutes). Colors: green (#22C55E) = up, red (#EF4444) = down, gray (#D1D5DB) = no data.

```php
// Livewire component to calculate segments
public function getUptimeBarProperty(): array
{
    $segments = [];
    $now = now();
    $sliceMinutes = 15;
    $totalSlices = (24 * 60) / $sliceMinutes; // 96 slices

    for ($i = $totalSlices - 1; $i >= 0; $i--) {
        $from = $now->copy()->subMinutes(($i + 1) * $sliceMinutes);
        $to = $now->copy()->subMinutes($i * $sliceMinutes);

        $checks = $this->monitor->checks()
            ->whereBetween('checked_at', [$from, $to])
            ->get();

        if ($checks->isEmpty()) {
            $segments[] = 'gray';
        } elseif ($checks->where('is_up', false)->count() > 0) {
            $segments[] = 'red';
        } else {
            $segments[] = 'green';
        }
    }

    return $segments;
}
```

```blade
{{-- Uptime bar blade component --}}
<div class="flex h-2 w-full gap-px rounded overflow-hidden">
    @foreach($segments as $segment)
        <div class="flex-1 {{ match($segment) {
            'green' => 'bg-green-500',
            'red' => 'bg-red-500',
            'gray' => 'bg-gray-300',
        } }}"></div>
    @endforeach
</div>
```

---

### 1.7 Livewire Components Structure

```
app/Livewire/
├── Uptime/
│   ├── UptimeOverview.php          # Global /uptime page
│   ├── MonitorRow.php              # Single monitor row (sub-component)
│   └── ConfigureMonitor.php        # Add/Edit monitor form
│
├── Sites/Detail/
│   └── SiteUptime.php              # Site-context uptime page (/sites/{site}/uptime)
│
└── Components/
    ├── UptimeBar.php               # 24h uptime bar visualization
    ├── ResponseTimeChart.php       # Line chart component
    └── UptimeStatsCard.php         # Stat card (24h, 7d, 30d, 365d)
```

---

---

## PART 2: APPLICATION SETTINGS & PROFILE

### 2.1 Overview

Three settings pages accessible from the sidebar:
1. **General Settings** — application-wide configuration
2. **Notification Settings** — email, Slack, Discord, webhook configuration
3. **Profile Settings** — user account, password, preferences

---

### 2.2 Database Schema

#### Migration: `app_settings`

A key-value store for application settings.

```php
Schema::create('app_settings', function (Blueprint $table) {
    $table->id();
    $table->string('group'); // general, notifications, monitoring, backups
    $table->string('key')->unique();
    $table->text('value')->nullable();
    $table->string('type')->default('string'); // string, boolean, integer, json
    $table->timestamps();
    
    $table->index(['group', 'key']);
});
```

#### Migration: `notification_channels`

```php
Schema::create('notification_channels', function (Blueprint $table) {
    $table->id();
    $table->string('name'); // "My Email", "Team Slack", "Discord Alerts"
    $table->string('type'); // email, slack, discord, webhook
    $table->json('config'); // type-specific config (see below)
    $table->boolean('is_default')->default(false);
    $table->boolean('is_active')->default(true);
    $table->timestamp('last_used_at')->nullable();
    $table->timestamps();
});
```

Notification channel config examples:
```json
// Email
{ "email": "andrei@simplead.ro" }

// Slack
{ "webhook_url": "https://hooks.slack.com/services/xxx/yyy/zzz" }

// Discord
{ "webhook_url": "https://discord.com/api/webhooks/xxx/yyy" }

// Webhook (generic)
{ "url": "https://example.com/webhook", "method": "POST", "headers": { "X-Token": "abc" } }
```

#### Migration: update `users` table

```php
Schema::table('users', function (Blueprint $table) {
    $table->string('timezone')->default('Europe/Bucharest');
    $table->string('date_format')->default('d M Y'); // display format preference
    $table->string('language')->default('en');
    $table->boolean('two_factor_enabled')->default(false);
    $table->text('two_factor_secret')->nullable();
    $table->json('two_factor_recovery_codes')->nullable();
    $table->string('avatar_path')->nullable();
});
```

---

### 2.3 Settings Service

A simple service to read/write app settings:

```php
// app/Services/SettingsService.php

class SettingsService
{
    public function get(string $key, mixed $default = null): mixed
    {
        $setting = AppSetting::where('key', $key)->first();
        
        if (!$setting) return $default;
        
        return match ($setting->type) {
            'boolean' => filter_var($setting->value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $setting->value,
            'json' => json_decode($setting->value, true),
            default => $setting->value,
        };
    }

    public function set(string $key, mixed $value, string $group = 'general', string $type = 'string'): void
    {
        $storeValue = match ($type) {
            'boolean' => $value ? '1' : '0',
            'json' => json_encode($value),
            default => (string) $value,
        };

        AppSetting::updateOrCreate(
            ['key' => $key],
            ['value' => $storeValue, 'group' => $group, 'type' => $type]
        );
    }

    public function getGroup(string $group): array
    {
        return AppSetting::where('group', $group)
            ->pluck('value', 'key')
            ->toArray();
    }
}
```

---

### 2.4 UI Pages

#### 2.4.1 General Settings (`/settings`)

```
┌─────────────────────────────────────────────────────────────────────┐
│  Settings                                                           │
│                                                                       │
│  [General] [Notifications] [Profile]     ← tab navigation           │
├─────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  ┌─ Application ───────────────────────────────────────────────────┐ │
│  │                                                                  │ │
│  │  Application Name                                                │ │
│  │  [ SimpleAd Manager                                           ] │ │
│  │                                                                  │ │
│  │  Application URL                                                 │ │
│  │  [ https://manager.simplead.ro                                ] │ │
│  │                                                                  │ │
│  │  Default Timezone                                                │ │
│  │  [ Europe/Bucharest ▼ ]                                         │ │
│  │                                                                  │ │
│  │  Date Format                                                     │ │
│  │  [ d M Y ▼ ]  Preview: 02 Feb 2026                              │ │
│  │                                                                  │ │
│  └──────────────────────────────────────────────────────────────────┘ │
│                                                                       │
│  ┌─ Monitoring Defaults ───────────────────────────────────────────┐ │
│  │                                                                  │ │
│  │  Default Check Interval                                          │ │
│  │  [ Every 5 minutes ▼ ]                                          │ │
│  │                                                                  │ │
│  │  Default Timeout                                                 │ │
│  │  [ 30 seconds ▼ ]                                               │ │
│  │                                                                  │ │
│  │  Alert After Failed Checks                                       │ │
│  │  [ 3 ▼ ] consecutive failures                                   │ │
│  │                                                                  │ │
│  │  Data Retention                                                  │ │
│  │  Keep uptime check history for [ 90 ▼ ] days                    │ │
│  │                                                                  │ │
│  └──────────────────────────────────────────────────────────────────┘ │
│                                                                       │
│  ┌─ Danger Zone ───────────────────────────────────────────────────┐ │
│  │                                                                  │ │
│  │  Purge all monitoring data          [ Purge Data ]               │ │
│  │  This will delete all uptime checks, incidents, and stats.      │ │
│  │                                                                  │ │
│  └──────────────────────────────────────────────────────────────────┘ │
│                                                                       │
│                                                    [Save Settings]   │
└─────────────────────────────────────────────────────────────────────┘
```

#### 2.4.2 Notification Settings (`/settings/notifications`)

```
┌─────────────────────────────────────────────────────────────────────┐
│  Settings                                                           │
│                                                                       │
│  [General] [Notifications] [Profile]                                 │
├─────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  Notification Channels                              [+ Add Channel]  │
│                                                                       │
│  ┌─────────────────────────────────────────────────────────────────┐ │
│  │  📧 Email — andrei@simplead.ro                                  │ │
│  │  Default channel  •  Active  •  Last used: 2h ago               │ │
│  │                                          [Test] [Edit] [Delete] │ │
│  ├─────────────────────────────────────────────────────────────────┤ │
│  │  💬 Slack — #monitoring-alerts                                   │ │
│  │  Active  •  Last used: 1d ago                                   │ │
│  │                                          [Test] [Edit] [Delete] │ │
│  ├─────────────────────────────────────────────────────────────────┤ │
│  │  🎮 Discord — Server Alerts                                      │ │
│  │  Inactive                                                        │ │
│  │                                     [Test] [Edit] [Activate] │    │
│  └─────────────────────────────────────────────────────────────────┘ │
│                                                                       │
│  ┌─ Notification Preferences ──────────────────────────────────────┐ │
│  │                                                                  │ │
│  │  When to notify:                                                 │ │
│  │  [✓] Site goes down                                              │ │
│  │  [✓] Site recovers (comes back up)                               │ │
│  │  [✓] SSL certificate expiring soon                               │ │
│  │  [ ] Domain expiring soon                                        │ │
│  │  [✓] Scheduled backup failed                                     │ │
│  │  [ ] WordPress updates available                                 │ │
│  │                                                                  │ │
│  │  Quiet hours:                                                    │ │
│  │  [ ] Enable quiet hours                                          │ │
│  │  From: [ 22:00 ]  To: [ 07:00 ]                                 │ │
│  │  (No notifications during these hours except critical alerts)    │ │
│  │                                                                  │ │
│  └──────────────────────────────────────────────────────────────────┘ │
│                                                                       │
│                                                    [Save Settings]   │
└─────────────────────────────────────────────────────────────────────┘
```

**Add/Edit Channel Modal:**

```
┌─────────────────────────────────────────────────────────────────────┐
│  Add Notification Channel                                    [✕]   │
├─────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  Channel Type                                                        │
│  [ Email ▼ ]                                                         │
│                                                                       │
│  Channel Name                                                        │
│  [ My Email                                                       ] │
│                                                                       │
│  Email Address                                                       │
│  [ andrei@simplead.ro                                             ] │
│                                                                       │
│  [✓] Set as default channel                                          │
│                                                                       │
│  ── For Slack type: ──                                               │
│  Webhook URL                                                         │
│  [ https://hooks.slack.com/services/...                           ] │
│                                                                       │
│  ── For Discord type: ──                                             │
│  Webhook URL                                                         │
│  [ https://discord.com/api/webhooks/...                           ] │
│                                                                       │
│  ── For Webhook type: ──                                             │
│  URL: [ https://...                                               ] │
│  Method: [ POST ▼ ]                                                  │
│  Custom Headers (optional):                                          │
│  Key: [ _______ ]  Value: [ _______ ]  [+ Add]                     │
│                                                                       │
│                                       [Cancel]  [Save Channel]       │
└─────────────────────────────────────────────────────────────────────┘
```

#### 2.4.3 Profile Settings (`/settings/profile`)

```
┌─────────────────────────────────────────────────────────────────────┐
│  Settings                                                           │
│                                                                       │
│  [General] [Notifications] [Profile]                                 │
├─────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  ┌─ Profile Information ───────────────────────────────────────────┐ │
│  │                                                                  │ │
│  │  ┌────┐                                                          │ │
│  │  │ AV │  [Change Avatar]                                         │ │
│  │  └────┘                                                          │ │
│  │                                                                  │ │
│  │  Full Name                                                       │ │
│  │  [ Andrei                                                     ] │ │
│  │                                                                  │ │
│  │  Email Address                                                   │ │
│  │  [ andrei@simplead.ro                                         ] │ │
│  │                                                                  │ │
│  │  Timezone                                                        │ │
│  │  [ Europe/Bucharest ▼ ]                                         │ │
│  │                                                                  │ │
│  │                                         [Update Profile]         │ │
│  └──────────────────────────────────────────────────────────────────┘ │
│                                                                       │
│  ┌─ Change Password ───────────────────────────────────────────────┐ │
│  │                                                                  │ │
│  │  Current Password                                                │ │
│  │  [ ●●●●●●●●                                                  ] │ │
│  │                                                                  │ │
│  │  New Password                                                    │ │
│  │  [ ●●●●●●●●●●●●                                              ] │ │
│  │                                                                  │ │
│  │  Confirm New Password                                            │ │
│  │  [ ●●●●●●●●●●●●                                              ] │ │
│  │                                                                  │ │
│  │                                        [Update Password]         │ │
│  └──────────────────────────────────────────────────────────────────┘ │
│                                                                       │
│  ┌─ Two-Factor Authentication ─────────────────────────────────────┐ │
│  │                                                                  │ │
│  │  Status: Not enabled                                             │ │
│  │  Add an extra layer of security to your account.                │ │
│  │                                                [Enable 2FA]      │ │
│  └──────────────────────────────────────────────────────────────────┘ │
│                                                                       │
│  ┌─ Sessions ──────────────────────────────────────────────────────┐ │
│  │                                                                  │ │
│  │  🖥  Chrome on Linux — Current session                           │ │
│  │     IP: 188.27.xxx.xxx  •  Last active: now                     │ │
│  │                                                                  │ │
│  │  📱 Safari on iPhone — 2 hours ago                               │ │
│  │     IP: 188.27.xxx.xxx                          [Revoke]        │ │
│  │                                                                  │ │
│  │                                    [Log Out All Other Sessions]  │ │
│  └──────────────────────────────────────────────────────────────────┘ │
│                                                                       │
│  ┌─ Danger Zone ───────────────────────────────────────────────────┐ │
│  │                                                                  │ │
│  │  Delete Account                     [Delete My Account]          │ │
│  │  Permanently delete your account and all associated data.       │ │
│  │                                                                  │ │
│  └──────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────┘
```

---

### 2.5 Notification Job

```php
// app/Jobs/NotifyIncident.php

class NotifyIncident implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public UptimeIncident $incident,
        public string $type // 'down' or 'recovery'
    ) {}

    public function handle(SettingsService $settings): void
    {
        // Check quiet hours
        if ($this->isQuietHours($settings)) return;

        $monitor = $this->incident->monitor()->with('site')->first();
        $channels = NotificationChannel::where('is_active', true)->get();

        // If monitor has specific contacts, use those; otherwise use defaults
        if ($monitor->alert_contacts) {
            $channels = $channels->whereIn('id', $monitor->alert_contacts);
        } else {
            $channels = $channels->where('is_default', true);
        }

        foreach ($channels as $channel) {
            match ($channel->type) {
                'email' => $this->sendEmail($channel, $monitor),
                'slack' => $this->sendSlack($channel, $monitor),
                'discord' => $this->sendDiscord($channel, $monitor),
                'webhook' => $this->sendWebhook($channel, $monitor),
            };

            $channel->update(['last_used_at' => now()]);
        }
    }

    private function sendEmail(NotificationChannel $channel, UptimeMonitor $monitor): void
    {
        $subject = $this->type === 'down'
            ? "🔴 DOWN: {$monitor->site->name} is not responding"
            : "🟢 RECOVERED: {$monitor->site->name} is back up";

        Mail::to($channel->config['email'])->send(
            new UptimeAlertMail($monitor, $this->incident, $this->type)
        );
    }

    private function sendSlack(NotificationChannel $channel, UptimeMonitor $monitor): void
    {
        $color = $this->type === 'down' ? '#EF4444' : '#22C55E';
        $status = $this->type === 'down' ? '🔴 DOWN' : '🟢 RECOVERED';

        Http::post($channel->config['webhook_url'], [
            'attachments' => [[
                'color' => $color,
                'title' => "{$status}: {$monitor->site->name}",
                'text' => $this->type === 'down'
                    ? "URL: {$monitor->url}\nCause: {$this->incident->cause}\nStarted: {$this->incident->started_at->format('H:i:s')}"
                    : "URL: {$monitor->url}\nDowntime: {$this->incident->duration}\nResolved: {$this->incident->resolved_at->format('H:i:s')}",
                'footer' => 'SimpleAd Manager',
                'ts' => now()->timestamp,
            ]],
        ]);
    }

    private function sendDiscord(NotificationChannel $channel, UptimeMonitor $monitor): void
    {
        $color = $this->type === 'down' ? 0xEF4444 : 0x22C55E;
        $status = $this->type === 'down' ? '🔴 DOWN' : '🟢 RECOVERED';

        Http::post($channel->config['webhook_url'], [
            'embeds' => [[
                'title' => "{$status}: {$monitor->site->name}",
                'description' => $this->type === 'down'
                    ? "**URL:** {$monitor->url}\n**Cause:** {$this->incident->cause}"
                    : "**URL:** {$monitor->url}\n**Downtime:** {$this->incident->duration}",
                'color' => $color,
                'timestamp' => now()->toIso8601String(),
                'footer' => ['text' => 'SimpleAd Manager'],
            ]],
        ]);
    }

    private function sendWebhook(NotificationChannel $channel, UptimeMonitor $monitor): void
    {
        $method = strtolower($channel->config['method'] ?? 'POST');
        $headers = $channel->config['headers'] ?? [];

        Http::withHeaders($headers)->{$method}($channel->config['url'], [
            'type' => $this->type,
            'site' => $monitor->site->name,
            'url' => $monitor->url,
            'incident' => [
                'cause' => $this->incident->cause,
                'started_at' => $this->incident->started_at->toIso8601String(),
                'resolved_at' => $this->incident->resolved_at?->toIso8601String(),
                'duration' => $this->incident->duration,
            ],
        ]);
    }

    private function isQuietHours(SettingsService $settings): bool
    {
        if (!$settings->get('notifications.quiet_hours_enabled', false)) return false;

        $from = $settings->get('notifications.quiet_hours_from', '22:00');
        $to = $settings->get('notifications.quiet_hours_to', '07:00');
        $now = now()->format('H:i');

        if ($from > $to) {
            return $now >= $from || $now <= $to;
        }
        return $now >= $from && $now <= $to;
    }
}
```

---

### 2.6 Livewire Components Structure

```
app/Livewire/
├── Settings/
│   ├── GeneralSettings.php
│   ├── NotificationSettings.php
│   ├── ProfileSettings.php
│   └── Components/
│       ├── ChannelForm.php          # Add/Edit notification channel modal
│       └── SessionManager.php       # Active sessions list
```

---

### 2.7 Settings Route Tabs

The three settings pages share a tab navigation at the top. Create a Blade partial:

```blade
{{-- resources/views/livewire/settings/partials/settings-tabs.blade.php --}}
<div class="mb-6 border-b border-gray-200">
    <nav class="flex gap-6">
        <a href="{{ route('settings.general') }}"
           class="border-b-2 px-1 pb-3 text-sm font-medium transition
                  {{ request()->routeIs('settings.general') 
                      ? 'border-purple-500 text-purple-600' 
                      : 'border-transparent text-gray-500 hover:text-gray-700' }}">
            General
        </a>
        <a href="{{ route('settings.notification') }}"
           class="border-b-2 px-1 pb-3 text-sm font-medium transition
                  {{ request()->routeIs('settings.notification') 
                      ? 'border-purple-500 text-purple-600' 
                      : 'border-transparent text-gray-500 hover:text-gray-700' }}">
            Notifications
        </a>
        <a href="{{ route('settings.profile') }}"
           class="border-b-2 px-1 pb-3 text-sm font-medium transition
                  {{ request()->routeIs('settings.profile') 
                      ? 'border-purple-500 text-purple-600' 
                      : 'border-transparent text-gray-500 hover:text-gray-700' }}">
            Profile
        </a>
    </nav>
</div>
```

---

### 2.8 New Routes Needed

Add to existing routes in `web.php`:

```php
// These should already exist from architecture doc, but confirm:
Route::prefix('/settings')->group(function () {
    Route::get('/', Settings\GeneralSettings::class)->name('settings.general');
    Route::get('/notifications', Settings\NotificationSettings::class)->name('settings.notification');
    Route::get('/profile', Settings\ProfileSettings::class)->name('settings.profile');
});
```

---

## PART 3: IMPLEMENTATION CHECKLIST

### Uptime Monitoring
- [ ] Create migrations: uptime_monitors, uptime_checks, uptime_incidents
- [ ] Create models: UptimeMonitor, UptimeCheck, UptimeIncident
- [ ] Install Guzzle (`composer require guzzlehttp/guzzle`)
- [ ] Create CheckUptime job with full HTTP checking logic
- [ ] Create scheduler entry to dispatch checks every minute
- [ ] Create Livewire: UptimeOverview (global page with monitor list)
- [ ] Create Livewire: SiteUptime (site-context detail page)
- [ ] Create Livewire: ConfigureMonitor (add/edit form)
- [ ] Create UptimeBar Blade component (24h visualization)
- [ ] Integrate response time chart (ApexCharts via CDN or Chart.js)
- [ ] Add "Test Now" button (manual check trigger)
- [ ] Add Pause/Resume functionality
- [ ] Wire up to existing Site model (site hasOne uptimeMonitor)
- [ ] Update site card to show live uptime data
- [ ] Seed sample uptime data for testing UI
- [ ] Update dashboard to show uptime summary

### Settings & Profile
- [ ] Create migrations: app_settings, notification_channels, update users table
- [ ] Create models: AppSetting, NotificationChannel
- [ ] Create SettingsService
- [ ] Create Livewire: GeneralSettings with form
- [ ] Create Livewire: NotificationSettings with channels CRUD
- [ ] Create Livewire: ProfileSettings with profile update, password change
- [ ] Create ChannelForm sub-component (modal for add/edit channel)
- [ ] Create settings tabs partial
- [ ] Create NotifyIncident job
- [ ] Create UptimeAlertMail mailable
- [ ] Add "Test" button for notification channels
- [ ] Implement quiet hours logic
- [ ] Wire notification channels to uptime incidents

### Integration Points
- [ ] UptimeMonitor belongs to Site
- [ ] Site card shows uptime %, response time, status dot from monitor
- [ ] Global uptime page aggregates all monitors
- [ ] Notifications use channels from settings
- [ ] Monitor defaults come from general settings
