# SimpleAd Manager — Feature Spec: SSL & Domain Monitoring

---

## Overview

Monitor SSL certificates and domain registrations for all managed sites. Track expiry dates, detect issues, send alerts before expiration, and display status across the UI (site cards, site overview, dedicated Security page).

This module leverages SSL data already captured by the uptime checker and adds dedicated domain WHOIS tracking.

---

## PART 1: SSL CERTIFICATE MONITORING

### 1.1 Database Schema

#### Migration: `ssl_certificates`

```php
Schema::create('ssl_certificates', function (Blueprint $table) {
    $table->id();
    $table->foreignId('site_id')->constrained()->onDelete('cascade');
    
    // Certificate details
    $table->string('domain'); // primary domain on the cert
    $table->json('san_domains')->nullable(); // Subject Alternative Names (all domains covered)
    $table->string('issuer')->nullable(); // Let's Encrypt, Cloudflare, DigiCert, etc.
    $table->string('issuer_organization')->nullable();
    $table->string('protocol_version')->nullable(); // TLSv1.2, TLSv1.3
    $table->string('cipher_suite')->nullable();
    $table->string('signature_algorithm')->nullable(); // SHA256withRSA, etc.
    $table->integer('key_size')->nullable(); // 2048, 4096
    
    // Validity
    $table->timestamp('issued_at')->nullable();
    $table->timestamp('expires_at')->nullable();
    $table->integer('days_remaining')->nullable(); // cached, updated daily
    
    // Status
    $table->string('status')->default('unknown'); // valid, expiring_soon, expired, invalid, error
    $table->text('status_detail')->nullable(); // human-readable status explanation
    
    // Chain info
    $table->boolean('chain_valid')->default(true);
    $table->text('chain_detail')->nullable();
    
    // Alert config
    $table->integer('warn_days_before')->default(30); // alert X days before expiry
    $table->boolean('alerts_enabled')->default(true);
    $table->timestamp('last_alert_sent_at')->nullable();
    
    // Check tracking
    $table->timestamp('last_checked_at')->nullable();
    $table->timestamp('next_check_at')->nullable();
    
    $table->timestamps();
    
    $table->index(['site_id']);
    $table->index(['status']);
    $table->index(['expires_at']);
    $table->index(['days_remaining']);
});
```

#### Migration: `ssl_check_history`

```php
Schema::create('ssl_check_history', function (Blueprint $table) {
    $table->id();
    $table->foreignId('ssl_certificate_id')->constrained()->onDelete('cascade');
    
    $table->string('status'); // valid, expiring_soon, expired, invalid, error
    $table->integer('days_remaining')->nullable();
    $table->string('issuer')->nullable();
    $table->string('protocol_version')->nullable();
    $table->boolean('chain_valid')->nullable();
    $table->text('error_message')->nullable();
    $table->integer('response_time_ms')->nullable(); // time to complete SSL handshake
    
    $table->timestamp('checked_at');
    
    $table->index(['ssl_certificate_id', 'checked_at']);
});
```

### 1.2 Models

```php
// app/Models/SslCertificate.php

class SslCertificate extends Model
{
    protected $casts = [
        'san_domains' => 'array',
        'chain_valid' => 'boolean',
        'alerts_enabled' => 'boolean',
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'next_check_at' => 'datetime',
        'last_alert_sent_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(SslCheckHistory::class);
    }

    // Computed status based on days remaining
    public function getStatusColorAttribute(): string
    {
        return match(true) {
            $this->status === 'expired' || $this->status === 'invalid' => 'red',
            $this->status === 'expiring_soon' => 'yellow',
            $this->status === 'valid' => 'green',
            default => 'gray',
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            'valid' => 'Valid',
            'expiring_soon' => "Expiring in {$this->days_remaining} days",
            'expired' => 'Expired',
            'invalid' => 'Invalid',
            'error' => 'Check Error',
            default => 'Unknown',
        };
    }
}
```

### 1.3 SSL Check Job

```php
// app/Jobs/CheckSslCertificate.php

class CheckSslCertificate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public SslCertificate $certificate) {}

    public function handle(): void
    {
        $domain = $this->certificate->domain;
        $result = $this->fetchSslInfo($domain);

        // Update certificate record
        $this->certificate->update([
            'issuer' => $result['issuer'],
            'issuer_organization' => $result['issuer_org'],
            'san_domains' => $result['san_domains'],
            'protocol_version' => $result['protocol'],
            'cipher_suite' => $result['cipher'],
            'signature_algorithm' => $result['signature_alg'],
            'key_size' => $result['key_size'],
            'issued_at' => $result['issued_at'],
            'expires_at' => $result['expires_at'],
            'days_remaining' => $result['days_remaining'],
            'chain_valid' => $result['chain_valid'],
            'chain_detail' => $result['chain_detail'],
            'status' => $result['status'],
            'status_detail' => $result['status_detail'],
            'last_checked_at' => now(),
            'next_check_at' => now()->addHours(12), // check twice daily
        ]);

        // Save history
        $this->certificate->history()->create([
            'status' => $result['status'],
            'days_remaining' => $result['days_remaining'],
            'issuer' => $result['issuer'],
            'protocol_version' => $result['protocol'],
            'chain_valid' => $result['chain_valid'],
            'error_message' => $result['error'],
            'response_time_ms' => $result['handshake_time'],
            'checked_at' => now(),
        ]);

        // Send alerts if needed
        $this->checkAlerts($result);
    }

    private function fetchSslInfo(string $domain): array
    {
        $result = [
            'issuer' => null,
            'issuer_org' => null,
            'san_domains' => [],
            'protocol' => null,
            'cipher' => null,
            'signature_alg' => null,
            'key_size' => null,
            'issued_at' => null,
            'expires_at' => null,
            'days_remaining' => null,
            'chain_valid' => false,
            'chain_detail' => null,
            'status' => 'error',
            'status_detail' => null,
            'error' => null,
            'handshake_time' => null,
        ];

        try {
            $startTime = microtime(true);

            // Connect and capture full certificate info
            $context = stream_context_create([
                'ssl' => [
                    'capture_peer_cert' => true,
                    'capture_peer_cert_chain' => true,
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'peer_name' => $domain,
                    'SNI_enabled' => true,
                ],
            ]);

            $stream = @stream_socket_client(
                "ssl://{$domain}:443",
                $errno, $errstr, 15,
                STREAM_CLIENT_CONNECT, $context
            );

            $handshakeTime = (int)((microtime(true) - $startTime) * 1000);
            $result['handshake_time'] = $handshakeTime;

            if (!$stream) {
                $result['error'] = "Connection failed: {$errstr} (errno: {$errno})";
                $result['status_detail'] = 'Could not establish SSL connection';
                return $result;
            }

            $params = stream_context_get_params($stream);
            $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate']);

            // Protocol & cipher from stream metadata
            $meta = stream_get_meta_data($stream);
            if (isset($meta['crypto'])) {
                $result['protocol'] = $meta['crypto']['protocol'] ?? null;
                $result['cipher'] = $meta['crypto']['cipher_name'] ?? null;
            }

            fclose($stream);

            if (!$cert) {
                $result['error'] = 'Could not parse certificate';
                $result['status_detail'] = 'SSL certificate could not be parsed';
                return $result;
            }

            // Parse certificate details
            $result['issuer'] = $cert['issuer']['CN'] ?? null;
            $result['issuer_org'] = $cert['issuer']['O'] ?? null;
            $result['signature_alg'] = $cert['signatureTypeSN'] ?? null;

            // Key size
            $pubKey = openssl_pkey_get_public($params['options']['ssl']['peer_certificate']);
            $keyDetails = openssl_pkey_get_details($pubKey);
            $result['key_size'] = $keyDetails['bits'] ?? null;

            // SAN domains
            if (isset($cert['extensions']['subjectAltName'])) {
                $sans = explode(',', $cert['extensions']['subjectAltName']);
                $result['san_domains'] = array_map(function ($san) {
                    return trim(str_replace('DNS:', '', $san));
                }, $sans);
            }

            // Validity dates
            $result['issued_at'] = Carbon::createFromTimestamp($cert['validFrom_time_t']);
            $result['expires_at'] = Carbon::createFromTimestamp($cert['validTo_time_t']);
            $result['days_remaining'] = (int) now()->diffInDays($result['expires_at'], false);

            // Chain validation
            $chain = $params['options']['ssl']['peer_certificate_chain'] ?? [];
            $result['chain_valid'] = count($chain) >= 2;
            $result['chain_detail'] = count($chain) . ' certificates in chain';

            // Determine status
            if ($result['days_remaining'] < 0) {
                $result['status'] = 'expired';
                $result['status_detail'] = 'Certificate has expired';
            } elseif ($result['days_remaining'] <= $this->certificate->warn_days_before) {
                $result['status'] = 'expiring_soon';
                $result['status_detail'] = "Certificate expires in {$result['days_remaining']} days";
            } else {
                $result['status'] = 'valid';
                $result['status_detail'] = "Certificate valid for {$result['days_remaining']} more days";
            }

        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
            $result['status'] = 'error';
            $result['status_detail'] = 'SSL check failed: ' . $e->getMessage();
        }

        return $result;
    }

    private function checkAlerts(array $result): void
    {
        if (!$this->certificate->alerts_enabled) return;

        $shouldAlert = false;
        $alertType = null;

        if ($result['status'] === 'expired') {
            $shouldAlert = true;
            $alertType = 'ssl_expired';
        } elseif ($result['status'] === 'expiring_soon') {
            // Only alert once per day for expiring certs
            if (!$this->certificate->last_alert_sent_at || 
                $this->certificate->last_alert_sent_at->lt(now()->subDay())) {
                $shouldAlert = true;
                $alertType = 'ssl_expiring';
            }
        } elseif ($result['status'] === 'invalid' || $result['status'] === 'error') {
            $shouldAlert = true;
            $alertType = 'ssl_error';
        }

        if ($shouldAlert) {
            NotifySslAlert::dispatch($this->certificate, $alertType);
            $this->certificate->update(['last_alert_sent_at' => now()]);
        }
    }
}
```

### 1.4 SSL Alert Notification Job

```php
// app/Jobs/NotifySslAlert.php

class NotifySslAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public SslCertificate $certificate,
        public string $alertType // ssl_expired, ssl_expiring, ssl_error
    ) {}

    public function handle(): void
    {
        $site = $this->certificate->site;
        $channels = NotificationChannel::where('is_active', true)
            ->where('is_default', true)
            ->get();

        $subject = match($this->alertType) {
            'ssl_expired' => "🔴 SSL EXPIRED: {$site->name}",
            'ssl_expiring' => "🟡 SSL Expiring Soon: {$site->name} ({$this->certificate->days_remaining} days)",
            'ssl_error' => "⚠️ SSL Error: {$site->name}",
        };

        $message = match($this->alertType) {
            'ssl_expired' => "The SSL certificate for {$this->certificate->domain} has expired on {$this->certificate->expires_at->format('d M Y')}. Renew immediately.",
            'ssl_expiring' => "The SSL certificate for {$this->certificate->domain} expires on {$this->certificate->expires_at->format('d M Y')} ({$this->certificate->days_remaining} days remaining).",
            'ssl_error' => "SSL check failed for {$this->certificate->domain}: {$this->certificate->status_detail}",
        };

        foreach ($channels as $channel) {
            // Reuse same notification pattern from NotifyIncident
            // Send via email, Slack, Discord, webhook
            match ($channel->type) {
                'email' => Mail::to($channel->config['email'])->send(new SslAlertMail($site, $this->certificate, $subject, $message)),
                'slack' => $this->sendSlack($channel, $subject, $message),
                'discord' => $this->sendDiscord($channel, $subject, $message),
                'webhook' => $this->sendWebhook($channel, $site),
            };
        }
    }

    // Slack, Discord, Webhook methods follow same pattern as NotifyIncident
    // Use yellow color for expiring, red for expired/error
}
```

---

## PART 2: DOMAIN MONITORING

### 2.1 Database Schema

#### Migration: `domain_monitors`

```php
Schema::create('domain_monitors', function (Blueprint $table) {
    $table->id();
    $table->foreignId('site_id')->constrained()->onDelete('cascade');
    
    // Domain info
    $table->string('domain'); // simplead.ro
    $table->string('tld')->nullable(); // .ro, .com, .net
    
    // Registrar info
    $table->string('registrar')->nullable(); // GoDaddy, Namecheap, etc.
    $table->string('registrar_url')->nullable();
    
    // WHOIS dates
    $table->timestamp('registered_at')->nullable(); // creation date
    $table->timestamp('expires_at')->nullable();
    $table->timestamp('updated_at_whois')->nullable(); // last WHOIS update (not Laravel updated_at)
    $table->integer('days_remaining')->nullable();
    
    // Status
    $table->string('status')->default('unknown'); // active, expiring_soon, expired, error
    $table->json('domain_statuses')->nullable(); // ["clientTransferProhibited", "clientDeleteProhibited"]
    
    // DNS
    $table->json('nameservers')->nullable(); // ["ns1.example.com", "ns2.example.com"]
    $table->string('dns_provider')->nullable(); // Cloudflare, Route53, etc.
    
    // Alert config
    $table->integer('warn_days_before')->default(30);
    $table->boolean('alerts_enabled')->default(true);
    $table->timestamp('last_alert_sent_at')->nullable();
    
    // Check tracking
    $table->timestamp('last_checked_at')->nullable();
    $table->timestamp('next_check_at')->nullable();
    
    $table->timestamps();
    
    $table->index(['site_id']);
    $table->index(['status']);
    $table->index(['expires_at']);
});
```

#### Migration: `domain_check_history`

```php
Schema::create('domain_check_history', function (Blueprint $table) {
    $table->id();
    $table->foreignId('domain_monitor_id')->constrained()->onDelete('cascade');
    
    $table->string('status');
    $table->integer('days_remaining')->nullable();
    $table->string('registrar')->nullable();
    $table->json('nameservers')->nullable();
    $table->text('error_message')->nullable();
    $table->text('raw_whois')->nullable(); // store raw WHOIS for debugging
    
    $table->timestamp('checked_at');
    
    $table->index(['domain_monitor_id', 'checked_at']);
});
```

### 2.2 Domain Check Job

```php
// app/Jobs/CheckDomainExpiry.php

class CheckDomainExpiry implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public DomainMonitor $domainMonitor) {}

    public function handle(): void
    {
        $domain = $this->domainMonitor->domain;
        $result = $this->lookupWhois($domain);

        $this->domainMonitor->update([
            'registrar' => $result['registrar'],
            'registrar_url' => $result['registrar_url'],
            'registered_at' => $result['registered_at'],
            'expires_at' => $result['expires_at'],
            'updated_at_whois' => $result['updated_at'],
            'days_remaining' => $result['days_remaining'],
            'domain_statuses' => $result['statuses'],
            'nameservers' => $result['nameservers'],
            'dns_provider' => $this->detectDnsProvider($result['nameservers']),
            'status' => $result['status'],
            'last_checked_at' => now(),
            'next_check_at' => now()->addDay(), // check once daily
        ]);

        // Save history
        $this->domainMonitor->history()->create([
            'status' => $result['status'],
            'days_remaining' => $result['days_remaining'],
            'registrar' => $result['registrar'],
            'nameservers' => $result['nameservers'],
            'error_message' => $result['error'],
            'raw_whois' => $result['raw'],
            'checked_at' => now(),
        ]);

        // Alerts
        $this->checkAlerts($result);
    }

    private function lookupWhois(string $domain): array
    {
        $result = [
            'registrar' => null,
            'registrar_url' => null,
            'registered_at' => null,
            'expires_at' => null,
            'updated_at' => null,
            'days_remaining' => null,
            'statuses' => [],
            'nameservers' => [],
            'status' => 'error',
            'error' => null,
            'raw' => null,
        ];

        try {
            // Use PHP WHOIS library or shell command
            $raw = shell_exec("whois {$domain} 2>&1");
            $result['raw'] = $raw;

            if (!$raw || str_contains($raw, 'No match') || str_contains($raw, 'NOT FOUND')) {
                $result['error'] = 'Domain not found in WHOIS';
                return $result;
            }

            // Parse common WHOIS fields
            $result['registrar'] = $this->extractWhoisField($raw, [
                'Registrar:', 'registrar:', 'Sponsoring Registrar:',
            ]);

            $result['registrar_url'] = $this->extractWhoisField($raw, [
                'Registrar URL:', 'registrar url:',
            ]);

            // Expiry date
            $expiryStr = $this->extractWhoisField($raw, [
                'Registry Expiry Date:', 'Registrar Registration Expiration Date:',
                'Expiration Date:', 'paid-till:', 'Expiry Date:', 'expire:',
            ]);
            if ($expiryStr) {
                $result['expires_at'] = Carbon::parse($expiryStr);
                $result['days_remaining'] = (int) now()->diffInDays($result['expires_at'], false);
            }

            // Creation date
            $createdStr = $this->extractWhoisField($raw, [
                'Creation Date:', 'Created Date:', 'created:', 'Registration Date:',
            ]);
            if ($createdStr) {
                $result['registered_at'] = Carbon::parse($createdStr);
            }

            // Updated date
            $updatedStr = $this->extractWhoisField($raw, [
                'Updated Date:', 'Last Updated:', 'changed:',
            ]);
            if ($updatedStr) {
                $result['updated_at'] = Carbon::parse($updatedStr);
            }

            // Nameservers
            preg_match_all('/Name Server:\s*(.+)/i', $raw, $nsMatches);
            if (!empty($nsMatches[1])) {
                $result['nameservers'] = array_map('strtolower', array_map('trim', $nsMatches[1]));
            }

            // Domain statuses
            preg_match_all('/Domain Status:\s*(.+)/i', $raw, $statusMatches);
            if (!empty($statusMatches[1])) {
                $result['statuses'] = array_map('trim', $statusMatches[1]);
            }

            // Determine status
            if ($result['days_remaining'] !== null) {
                if ($result['days_remaining'] < 0) {
                    $result['status'] = 'expired';
                } elseif ($result['days_remaining'] <= $this->domainMonitor->warn_days_before) {
                    $result['status'] = 'expiring_soon';
                } else {
                    $result['status'] = 'active';
                }
            }

        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    private function extractWhoisField(string $raw, array $labels): ?string
    {
        foreach ($labels as $label) {
            if (preg_match('/' . preg_quote($label, '/') . '\s*(.+)/i', $raw, $match)) {
                return trim($match[1]);
            }
        }
        return null;
    }

    private function detectDnsProvider(?array $nameservers): ?string
    {
        if (!$nameservers) return null;

        $ns = strtolower(implode(' ', $nameservers));

        return match(true) {
            str_contains($ns, 'cloudflare') => 'Cloudflare',
            str_contains($ns, 'awsdns') => 'AWS Route 53',
            str_contains($ns, 'google') => 'Google Cloud DNS',
            str_contains($ns, 'digitalocean') => 'DigitalOcean',
            str_contains($ns, 'hetzner') => 'Hetzner',
            str_contains($ns, 'namecheap') => 'Namecheap',
            str_contains($ns, 'godaddy') || str_contains($ns, 'domaincontrol') => 'GoDaddy',
            default => null,
        };
    }

    private function checkAlerts(array $result): void
    {
        if (!$this->domainMonitor->alerts_enabled) return;

        if ($result['status'] === 'expired' || $result['status'] === 'expiring_soon') {
            if (!$this->domainMonitor->last_alert_sent_at ||
                $this->domainMonitor->last_alert_sent_at->lt(now()->subDay())) {
                
                NotifyDomainAlert::dispatch($this->domainMonitor, $result['status']);
                $this->domainMonitor->update(['last_alert_sent_at' => now()]);
            }
        }
    }
}
```

### 2.3 Scheduler

```php
// Add to existing scheduler

// SSL checks — every 12 hours
Schedule::call(function () {
    SslCertificate::where(function ($q) {
        $q->whereNull('next_check_at')
          ->orWhere('next_check_at', '<=', now());
    })->each(function ($cert) {
        CheckSslCertificate::dispatch($cert);
    });
})->everyTwelveHours();

// Domain checks — once daily
Schedule::call(function () {
    DomainMonitor::where(function ($q) {
        $q->whereNull('next_check_at')
          ->orWhere('next_check_at', '<=', now());
    })->each(function ($domain) {
        CheckDomainExpiry::dispatch($domain);
    });
})->daily();
```

---

## PART 3: SITE RELATIONSHIPS

### 3.1 Update Site Model

```php
// Add to app/Models/Site.php

public function sslCertificate(): HasOne
{
    return $this->hasOne(SslCertificate::class);
}

public function domainMonitor(): HasOne
{
    return $this->hasOne(DomainMonitor::class);
}
```

### 3.2 Auto-create SSL & Domain monitors when site is added

When a new site is created, automatically create SSL and Domain monitor records:

```php
// In Site model boot() or an observer

protected static function booted(): void
{
    static::created(function (Site $site) {
        $domain = parse_url($site->url, PHP_URL_HOST);

        // Auto-create SSL monitor if HTTPS
        if (str_starts_with($site->url, 'https://')) {
            $cert = $site->sslCertificate()->create([
                'domain' => $domain,
                'warn_days_before' => 30,
                'alerts_enabled' => true,
            ]);
            // Run first check immediately
            CheckSslCertificate::dispatch($cert);
        }

        // Auto-create domain monitor
        // Extract root domain (remove www and subdomains for WHOIS)
        $rootDomain = $this->extractRootDomain($domain);
        $site->domainMonitor()->create([
            'domain' => $rootDomain,
            'tld' => '.' . pathinfo($rootDomain, PATHINFO_EXTENSION),
            'warn_days_before' => 30,
            'alerts_enabled' => true,
        ]);
        CheckDomainExpiry::dispatch($site->domainMonitor);
    });
}

// Helper to extract root domain
private static function extractRootDomain(string $domain): string
{
    // Remove www prefix
    $domain = preg_replace('/^www\./', '', $domain);
    
    // For simple cases: get last two parts
    // For complex TLDs (.co.uk, .com.ro) you might need a library
    $parts = explode('.', $domain);
    if (count($parts) > 2) {
        return implode('.', array_slice($parts, -2));
    }
    return $domain;
}
```

---

## PART 4: UI PAGES

### 4.1 Security Page — Site Context (`/sites/{site}/security`)

This is the main page for SSL & Domain info within a site.

```
┌─────────────────────────────────────────────────────────────────────┐
│  Security — simplead.ro                                    [Refresh] │
├─────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  ┌─ SSL Certificate ───────────────────────────────────────────────┐ │
│  │                                                                  │ │
│  │  ┌────────┐                                                      │ │
│  │  │ 🔒     │  Status: ● Valid                                    │ │
│  │  │ VALID  │  Expires: 15 Mar 2026 (41 days remaining)           │ │
│  │  └────────┘  Issuer: Let's Encrypt                              │ │
│  │                                                                  │ │
│  │  ── Details ──────────────────────────────────────────────────── │ │
│  │                                                                  │ │
│  │  Domain          simplead.ro                                     │ │
│  │  SAN Domains     simplead.ro, www.simplead.ro                   │ │
│  │  Protocol        TLSv1.3                                        │ │
│  │  Cipher          TLS_AES_256_GCM_SHA384                        │ │
│  │  Key Size        2048-bit RSA                                    │ │
│  │  Signature       SHA256withRSA                                   │ │
│  │  Issued          15 Dec 2025                                     │ │
│  │  Expires         15 Mar 2026                                     │ │
│  │  Chain           ● Valid (3 certificates)                       │ │
│  │                                                                  │ │
│  │  ── Alert Settings ───────────────────────────────────────────── │ │
│  │  [✓] Alerts enabled    Warn [ 30 ▼ ] days before expiry        │ │
│  │                                                                  │ │
│  │  Last checked: 2 hours ago                     [Check Now]       │ │
│  └──────────────────────────────────────────────────────────────────┘ │
│                                                                       │
│  ┌─ Domain Registration ───────────────────────────────────────────┐ │
│  │                                                                  │ │
│  │  ┌────────┐                                                      │ │
│  │  │ 🌐     │  Status: ● Active                                  │ │
│  │  │ ACTIVE │  Expires: 10 Nov 2026 (281 days remaining)          │ │
│  │  └────────┘  Registrar: Namecheap                               │ │
│  │                                                                  │ │
│  │  ── Details ──────────────────────────────────────────────────── │ │
│  │                                                                  │ │
│  │  Domain          simplead.ro                                     │ │
│  │  Registrar       Namecheap (namecheap.com)                      │ │
│  │  Registered      10 Nov 2020                                     │ │
│  │  Expires         10 Nov 2026                                     │ │
│  │  Nameservers     kyra.ns.cloudflare.com                         │ │
│  │                  zarek.ns.cloudflare.com                         │ │
│  │  DNS Provider    Cloudflare                                      │ │
│  │  Status Flags    clientTransferProhibited                       │ │
│  │                                                                  │ │
│  │  ── Alert Settings ───────────────────────────────────────────── │ │
│  │  [✓] Alerts enabled    Warn [ 30 ▼ ] days before expiry        │ │
│  │                                                                  │ │
│  │  Last checked: 6 hours ago                     [Check Now]       │ │
│  └──────────────────────────────────────────────────────────────────┘ │
│                                                                       │
│  ┌─ SSL History ───────────────────────────────────────────────────┐ │
│  │  Date          │ Status   │ Days Left │ Issuer       │ TLS     │ │
│  │ ───────────────────────────────────────────────────────────────  │ │
│  │  Feb 2, 10:00  │ ● Valid  │ 41        │ Let's Encrypt│ TLSv1.3│ │
│  │  Feb 1, 22:00  │ ● Valid  │ 42        │ Let's Encrypt│ TLSv1.3│ │
│  │  Feb 1, 10:00  │ ● Valid  │ 42        │ Let's Encrypt│ TLSv1.3│ │
│  │  Jan 31, 22:00 │ ● Valid  │ 43        │ Let's Encrypt│ TLSv1.3│ │
│  └──────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────┘
```

### 4.2 Update Site Card

The existing site card already has an SSL icon. Wire it to real data:

```blade
{{-- SSL indicator on site card --}}
@if($site->sslCertificate)
    <div class="flex items-center gap-1"
         title="SSL: {{ $site->sslCertificate->status_label }}">
        <svg class="h-3.5 w-3.5 {{ match($site->sslCertificate->status_color) {
            'green' => 'text-green-500',
            'yellow' => 'text-yellow-500',
            'red' => 'text-red-500',
            default => 'text-gray-400',
        } }}" ...>
            {{-- lock icon --}}
        </svg>
        @if($site->sslCertificate->days_remaining !== null)
            <span class="text-xs">{{ $site->sslCertificate->days_remaining }}d</span>
        @endif
    </div>
@endif
```

### 4.3 Update Site Overview

On the site overview page, add summary cards for SSL and Domain:

```blade
{{-- SSL summary card on site overview --}}
<x-ui.card>
    <div class="flex items-center justify-between">
        <div>
            <p class="text-sm font-medium text-gray-500">SSL Certificate</p>
            <p class="mt-1 text-lg font-semibold {{ $site->sslCertificate?->status_color === 'green' ? 'text-green-600' : 'text-red-600' }}">
                {{ $site->sslCertificate?->status_label ?? 'Not monitored' }}
            </p>
        </div>
        <div class="text-right text-sm text-gray-500">
            @if($site->sslCertificate?->expires_at)
                Expires {{ $site->sslCertificate->expires_at->format('d M Y') }}
            @endif
        </div>
    </div>
</x-ui.card>

{{-- Domain summary card on site overview --}}
<x-ui.card>
    <div class="flex items-center justify-between">
        <div>
            <p class="text-sm font-medium text-gray-500">Domain</p>
            <p class="mt-1 text-lg font-semibold {{ $site->domainMonitor?->status === 'active' ? 'text-green-600' : 'text-yellow-600' }}">
                {{ $site->domainMonitor?->days_remaining ? $site->domainMonitor->days_remaining . ' days left' : 'Not monitored' }}
            </p>
        </div>
        <div class="text-right text-sm text-gray-500">
            {{ $site->domainMonitor?->registrar ?? '' }}
        </div>
    </div>
</x-ui.card>
```

---

## PART 5: LIVEWIRE COMPONENTS

```
app/Livewire/
├── Sites/Detail/
│   └── SiteSecurity.php           # Main security page (SSL + Domain)
│
└── Components/
    ├── SslStatusCard.php           # SSL certificate detail card
    ├── DomainStatusCard.php        # Domain registration detail card
    └── SslHistoryTable.php         # SSL check history table
```

---

## PART 6: IMPLEMENTATION CHECKLIST

### SSL Monitoring
- [ ] Create migration: ssl_certificates
- [ ] Create migration: ssl_check_history
- [ ] Create model: SslCertificate (with casts, relationships, computed attributes)
- [ ] Create model: SslCheckHistory
- [ ] Create job: CheckSslCertificate (full SSL info fetching with stream_socket_client)
- [ ] Create job: NotifySslAlert
- [ ] Create mailable: SslAlertMail
- [ ] Add scheduler entry (every 12 hours)
- [ ] Wire SSL data into site card (status icon + days remaining)
- [ ] Wire SSL summary into site overview page

### Domain Monitoring
- [ ] Create migration: domain_monitors
- [ ] Create migration: domain_check_history
- [ ] Create model: DomainMonitor
- [ ] Create model: DomainCheckHistory
- [ ] Create job: CheckDomainExpiry (WHOIS parsing)
- [ ] Create job: NotifyDomainAlert
- [ ] Install `whois` system package (ensure available in Docker/server)
- [ ] Add scheduler entry (daily)
- [ ] Wire domain data into site overview page

### Security Page
- [ ] Create Livewire: SiteSecurity (full page with SSL + Domain sections)
- [ ] SSL details section with all cert info
- [ ] Domain details section with registrar, nameservers, statuses
- [ ] Alert settings inline edit (warn days, enable/disable)
- [ ] "Check Now" buttons for both SSL and Domain
- [ ] SSL history table
- [ ] Style everything to match WPMUDEV look

### Auto-creation
- [ ] Add Site model observer/boot to auto-create SSL + Domain monitors on site creation
- [ ] Trigger first check immediately on creation
- [ ] Ensure existing sites get monitors (write a one-time artisan command or seeder)

### Integration
- [ ] Add `sslCertificate` and `domainMonitor` relationships to Site model
- [ ] Update site card component with real SSL data
- [ ] Update site overview with SSL + Domain summary cards
- [ ] Connect alerts to existing notification channels from settings
- [ ] Update dashboard with SSL/Domain expiry warnings if any
