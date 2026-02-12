<?php

namespace App\Livewire\Sites\Detail;

use App\Models\CloudflareConnection;
use App\Models\Site;
use App\Models\SiteCloudflare as SiteCloudflareModel;
use App\Services\CloudflareService;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

class SiteCloudflare extends Component
{
    use WithFileUploads;

    public Site $site;
    public string $tab = 'dns';

    // Connection
    public ?int $selectedConnectionId = null;
    public ?string $selectedZoneId = null;

    // DNS form
    public string $dnsType = 'A';
    public string $dnsName = '';
    public string $dnsContent = '';
    public int $dnsTtl = 1;
    public bool $dnsProxied = true;

    // DNS edit modal
    public bool $showDnsEditModal = false;
    public ?string $editingDnsRecordId = null;
    public string $editDnsType = 'A';
    public string $editDnsName = '';
    public string $editDnsContent = '';
    public int $editDnsTtl = 1;
    public bool $editDnsProxied = true;

    // DNS import
    public $dnsImportFile = null;

    // Cache
    public string $purgeUrls = '';

    // Security
    public string $newSecurityLevel = 'medium';

    // Firewall modal
    public bool $showFirewallModal = false;
    public ?string $editingFirewallRuleId = null;
    public string $fwDescription = '';
    public string $fwExpression = '';
    public string $fwAction = 'block';

    // IP blocking
    public string $blockIp = '';
    public string $blockNote = '';

    // Analytics
    public string $analyticsPeriod = '-1440';

    public function mount(Site $site): void
    {
        $this->site = $site;

        if ($cf = $this->site->siteCloudflare) {
            $this->selectedConnectionId = $cf->cloudflare_connection_id;
        }
    }

    #[Computed]
    public function siteCloudflare(): ?SiteCloudflareModel
    {
        return $this->site->siteCloudflare;
    }

    #[Computed]
    public function connections()
    {
        return CloudflareConnection::where('is_valid', true)->orderBy('account_email')->get();
    }

    public function updatedSelectedConnectionId(): void
    {
        $this->selectedZoneId = null;
        unset($this->availableZones);
    }

    #[Computed]
    public function availableZones(): array
    {
        if (!$this->selectedConnectionId) {
            return [];
        }

        $connection = CloudflareConnection::find($this->selectedConnectionId);
        if (!$connection) {
            return [];
        }

        try {
            $service = new CloudflareService($connection);
            return $service->listZones();
        } catch (\Exception $e) {
            session()->flash('cf-error', 'Failed to fetch zones: ' . $e->getMessage());
            return [];
        }
    }

    public function connectToZone(): void
    {
        if (!$this->selectedConnectionId || !$this->selectedZoneId) {
            session()->flash('cf-error', 'Please select a connection and zone.');
            return;
        }

        $connection = CloudflareConnection::findOrFail($this->selectedConnectionId);
        $service = new CloudflareService($connection);

        try {
            $service->connectSiteToZone($this->site, $this->selectedZoneId);
            $this->site->load('siteCloudflare');
            unset($this->siteCloudflare);
            session()->flash('cf-success', 'Site connected to Cloudflare zone.');
        } catch (\Exception $e) {
            session()->flash('cf-error', 'Failed to connect: ' . $e->getMessage());
        }
    }

    public function disconnectZone(): void
    {
        $this->site->siteCloudflare?->delete();
        $this->site->load('siteCloudflare');
        unset($this->siteCloudflare);
        session()->flash('cf-success', 'Cloudflare zone disconnected.');
    }

    // DNS

    private function dnsCacheKey(): string
    {
        return "cf:{$this->siteCloudflare?->id}:dns";
    }

    #[Computed]
    public function dnsRecords(): array
    {
        $cf = $this->siteCloudflare;
        if (!$cf) {
            return [];
        }

        return Cache::remember($this->dnsCacheKey(), 120, function () use ($cf) {
            try {
                $service = new CloudflareService($cf->cloudflareConnection);
                return $service->listDnsRecords($cf->zone_id);
            } catch (\Exception $e) {
                return [];
            }
        });
    }

    public function addDnsRecord(): void
    {
        $this->validate([
            'dnsType' => 'required|in:A,AAAA,CNAME,MX,TXT,NS,SRV',
            'dnsName' => 'required|string|max:255',
            'dnsContent' => 'required|string|max:2048',
        ]);

        $cf = $this->siteCloudflare;
        if (!$cf) return;

        $service = new CloudflareService($cf->cloudflareConnection);

        try {
            $service->createDnsRecord($cf->zone_id, [
                'type' => $this->dnsType,
                'name' => $this->dnsName,
                'content' => $this->dnsContent,
                'ttl' => $this->dnsTtl,
                'proxied' => in_array($this->dnsType, ['A', 'AAAA', 'CNAME']) ? $this->dnsProxied : false,
            ]);

            $this->reset('dnsName', 'dnsContent');
            Cache::forget($this->dnsCacheKey());
            unset($this->dnsRecords);
            session()->flash('cf-success', 'DNS record created.');
        } catch (\Exception $e) {
            session()->flash('cf-error', 'Failed to create DNS record: ' . $e->getMessage());
        }
    }

    public function deleteDnsRecord(string $recordId): void
    {
        $cf = $this->siteCloudflare;
        if (!$cf) return;

        $service = new CloudflareService($cf->cloudflareConnection);

        try {
            $service->deleteDnsRecord($cf->zone_id, $recordId);
            Cache::forget($this->dnsCacheKey());
            unset($this->dnsRecords);
            session()->flash('cf-success', 'DNS record deleted.');
        } catch (\Exception $e) {
            session()->flash('cf-error', 'Failed to delete DNS record: ' . $e->getMessage());
        }
    }

    public function toggleProxy(string $recordId, string $type, string $name, string $content, int $ttl, bool $currentProxied): void
    {
        $cf = $this->siteCloudflare;
        if (!$cf) return;

        $service = new CloudflareService($cf->cloudflareConnection);

        try {
            $service->updateDnsRecord($cf->zone_id, $recordId, [
                'type' => $type,
                'name' => $name,
                'content' => $content,
                'ttl' => $ttl,
                'proxied' => !$currentProxied,
            ]);

            Cache::forget($this->dnsCacheKey());
            unset($this->dnsRecords);
        } catch (\Exception $e) {
            session()->flash('cf-error', 'Failed to toggle proxy: ' . $e->getMessage());
        }
    }

    // DNS Edit Modal

    public function openDnsEditModal(string $recordId, string $type, string $name, string $content, int $ttl, bool $proxied): void
    {
        $this->editingDnsRecordId = $recordId;
        $this->editDnsType = $type;
        $this->editDnsName = $name;
        $this->editDnsContent = $content;
        $this->editDnsTtl = $ttl;
        $this->editDnsProxied = $proxied;
        $this->showDnsEditModal = true;
    }

    public function updateDnsRecord(): void
    {
        $this->validate([
            'editDnsType' => 'required|in:A,AAAA,CNAME,MX,TXT,NS,SRV',
            'editDnsName' => 'required|string|max:255',
            'editDnsContent' => 'required|string|max:2048',
        ]);

        $cf = $this->siteCloudflare;
        if (!$cf || !$this->editingDnsRecordId) return;

        $service = new CloudflareService($cf->cloudflareConnection);

        try {
            $service->updateDnsRecord($cf->zone_id, $this->editingDnsRecordId, [
                'type' => $this->editDnsType,
                'name' => $this->editDnsName,
                'content' => $this->editDnsContent,
                'ttl' => $this->editDnsTtl,
                'proxied' => in_array($this->editDnsType, ['A', 'AAAA', 'CNAME']) ? $this->editDnsProxied : false,
            ]);

            $this->showDnsEditModal = false;
            Cache::forget($this->dnsCacheKey());
            unset($this->dnsRecords);
            session()->flash('cf-success', 'DNS record updated.');
        } catch (\Exception $e) {
            session()->flash('cf-error', 'Failed to update DNS record: ' . $e->getMessage());
        }
    }

    // DNS Import/Export

    public function exportDnsRecords()
    {
        $records = collect($this->dnsRecords)->map(fn ($r) => [
            'type' => $r['type'],
            'name' => $r['name'],
            'content' => $r['content'],
            'ttl' => $r['ttl'],
            'proxied' => $r['proxied'] ?? false,
        ])->values()->toArray();

        $json = json_encode($records, JSON_PRETTY_PRINT);
        $zoneName = $this->siteCloudflare?->zone_name ?? 'dns-records';

        return response()->streamDownload(function () use ($json) {
            echo $json;
        }, "{$zoneName}-dns-export.json", ['Content-Type' => 'application/json']);
    }

    public function importDnsRecords(): void
    {
        $this->validate([
            'dnsImportFile' => 'required|file|max:1024',
        ]);

        $cf = $this->siteCloudflare;
        if (!$cf) return;

        $contents = file_get_contents($this->dnsImportFile->getRealPath());
        $records = json_decode($contents, true);

        if (!is_array($records)) {
            session()->flash('cf-error', 'Invalid JSON file.');
            return;
        }

        if (count($records) > 500) {
            session()->flash('cf-error', 'Import limited to 500 records maximum.');
            return;
        }

        $allowedTypes = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA'];
        $service = new CloudflareService($cf->cloudflareConnection);
        $success = 0;
        $failed = 0;

        foreach ($records as $record) {
            if (!is_array($record)) {
                $failed++;
                continue;
            }

            $type = $record['type'] ?? null;
            if (!in_array($type, $allowedTypes) || empty($record['name']) || empty($record['content'])) {
                $failed++;
                continue;
            }

            try {
                $service->createDnsRecord($cf->zone_id, [
                    'type' => $type,
                    'name' => $record['name'],
                    'content' => $record['content'],
                    'ttl' => $record['ttl'] ?? 1,
                    'proxied' => $record['proxied'] ?? false,
                ]);
                $success++;
            } catch (\Exception $e) {
                $failed++;
            }
        }

        $this->dnsImportFile = null;
        Cache::forget($this->dnsCacheKey());
        unset($this->dnsRecords);
        session()->flash('cf-success', "Import complete: {$success} created, {$failed} failed.");
    }

    // Cache

    #[Computed]
    public function cachePurges()
    {
        $cf = $this->siteCloudflare;
        if (!$cf) {
            return collect();
        }

        return $cf->cachePurges()->with('purgedBy')->orderByDesc('purged_at')->limit(20)->get();
    }

    public function purgeEverything(): void
    {
        $cf = $this->siteCloudflare;
        if (!$cf) return;

        $service = new CloudflareService($cf->cloudflareConnection);

        try {
            $service->purgeEverything($cf->zone_id);

            $cf->cachePurges()->create([
                'type' => 'everything',
                'purged_by' => auth()->id(),
                'purged_at' => now(),
            ]);

            unset($this->cachePurges);
            session()->flash('cf-success', 'Cache purged successfully.');
        } catch (\Exception $e) {
            session()->flash('cf-error', 'Failed to purge cache: ' . $e->getMessage());
        }
    }

    public function purgeByUrls(): void
    {
        $urls = array_filter(array_map('trim', explode("\n", $this->purgeUrls)));

        if (empty($urls)) {
            session()->flash('cf-error', 'Please enter at least one URL.');
            return;
        }

        $cf = $this->siteCloudflare;
        if (!$cf) return;

        $service = new CloudflareService($cf->cloudflareConnection);

        try {
            $service->purgeByUrls($cf->zone_id, $urls);

            $cf->cachePurges()->create([
                'type' => 'urls',
                'targets' => $urls,
                'purged_by' => auth()->id(),
                'purged_at' => now(),
            ]);

            $this->purgeUrls = '';
            unset($this->cachePurges);
            session()->flash('cf-success', count($urls) . ' URL(s) purged from cache.');
        } catch (\Exception $e) {
            session()->flash('cf-error', 'Failed to purge URLs: ' . $e->getMessage());
        }
    }

    // Security

    private function securityCacheKey(): string
    {
        return "cf:{$this->siteCloudflare?->id}:security";
    }

    private function fwCacheKey(): string
    {
        return "cf:{$this->siteCloudflare?->id}:fw";
    }

    private function wafCacheKey(): string
    {
        return "cf:{$this->siteCloudflare?->id}:waf";
    }

    private function accessRulesCacheKey(): string
    {
        return "cf:{$this->siteCloudflare?->id}:access-rules";
    }

    #[Computed]
    public function securityLevel(): string
    {
        $cf = $this->siteCloudflare;
        if (!$cf) return 'medium';

        return Cache::remember($this->securityCacheKey(), 300, function () use ($cf) {
            try {
                $service = new CloudflareService($cf->cloudflareConnection);
                return $service->getSecurityLevel($cf->zone_id);
            } catch (\Exception $e) {
                return 'medium';
            }
        });
    }

    #[Computed]
    public function firewallRules(): array
    {
        $cf = $this->siteCloudflare;
        if (!$cf) return [];

        return Cache::remember($this->fwCacheKey(), 120, function () use ($cf) {
            try {
                $service = new CloudflareService($cf->cloudflareConnection);
                return $service->listFirewallRules($cf->zone_id);
            } catch (\Exception $e) {
                return [];
            }
        });
    }

    #[Computed]
    public function wafStatus(): string
    {
        $cf = $this->siteCloudflare;
        if (!$cf) return 'unknown';

        return Cache::remember($this->wafCacheKey(), 300, function () use ($cf) {
            try {
                $service = new CloudflareService($cf->cloudflareConnection);
                return $service->getWafStatus($cf->zone_id);
            } catch (\Exception $e) {
                return 'unknown';
            }
        });
    }

    #[Computed]
    public function accessRules(): array
    {
        $cf = $this->siteCloudflare;
        if (!$cf) return [];

        return Cache::remember($this->accessRulesCacheKey(), 120, function () use ($cf) {
            try {
                $service = new CloudflareService($cf->cloudflareConnection);
                return $service->listAccessRules($cf->zone_id);
            } catch (\Exception $e) {
                return [];
            }
        });
    }

    public function setSecurityLevel(): void
    {
        $cf = $this->siteCloudflare;
        if (!$cf) return;

        $service = new CloudflareService($cf->cloudflareConnection);

        try {
            $service->setSecurityLevel($cf->zone_id, $this->newSecurityLevel);
            Cache::forget($this->securityCacheKey());
            unset($this->securityLevel);
            session()->flash('cf-success', 'Security level updated.');
        } catch (\Exception $e) {
            session()->flash('cf-error', 'Failed to set security level: ' . $e->getMessage());
        }
    }

    public function toggleUnderAttack(): void
    {
        $cf = $this->siteCloudflare;
        if (!$cf) return;

        $service = new CloudflareService($cf->cloudflareConnection);
        $current = $this->securityLevel;
        $newLevel = $current === 'under_attack' ? 'medium' : 'under_attack';

        try {
            $service->setSecurityLevel($cf->zone_id, $newLevel);
            Cache::forget($this->securityCacheKey());
            unset($this->securityLevel);
            session()->flash('cf-success', $newLevel === 'under_attack' ? 'Under Attack mode enabled.' : 'Under Attack mode disabled.');
        } catch (\Exception $e) {
            session()->flash('cf-error', 'Failed to toggle Under Attack mode: ' . $e->getMessage());
        }
    }

    // Firewall CRUD

    public function openCreateFirewallModal(): void
    {
        $this->editingFirewallRuleId = null;
        $this->fwDescription = '';
        $this->fwExpression = '';
        $this->fwAction = 'block';
        $this->showFirewallModal = true;
    }

    public function openEditFirewallModal(string $ruleId, string $description, string $expression, string $action): void
    {
        $this->editingFirewallRuleId = $ruleId;
        $this->fwDescription = $description;
        $this->fwExpression = $expression;
        $this->fwAction = $action;
        $this->showFirewallModal = true;
    }

    public function saveFirewallRule(): void
    {
        $this->validate([
            'fwDescription' => 'required|string|max:255',
            'fwExpression' => 'required|string|max:4096',
            'fwAction' => 'required|in:block,challenge,js_challenge,managed_challenge,allow,log,bypass',
        ]);

        $cf = $this->siteCloudflare;
        if (!$cf) return;

        $service = new CloudflareService($cf->cloudflareConnection);

        try {
            $ruleData = [
                'action' => $this->fwAction,
                'description' => $this->fwDescription,
                'filter' => [
                    'expression' => $this->fwExpression,
                ],
            ];

            if ($this->editingFirewallRuleId) {
                $service->updateFirewallRule($cf->zone_id, $this->editingFirewallRuleId, $ruleData);
                session()->flash('cf-success', 'Firewall rule updated.');
            } else {
                $service->createFirewallRule($cf->zone_id, $ruleData);
                session()->flash('cf-success', 'Firewall rule created.');
            }

            $this->showFirewallModal = false;
            Cache::forget($this->fwCacheKey());
            unset($this->firewallRules);
        } catch (\Exception $e) {
            session()->flash('cf-error', 'Failed to save firewall rule: ' . $e->getMessage());
        }
    }

    public function deleteFirewallRule(string $ruleId): void
    {
        $cf = $this->siteCloudflare;
        if (!$cf) return;

        $service = new CloudflareService($cf->cloudflareConnection);

        try {
            $service->deleteFirewallRule($cf->zone_id, $ruleId);
            Cache::forget($this->fwCacheKey());
            unset($this->firewallRules);
            session()->flash('cf-success', 'Firewall rule deleted.');
        } catch (\Exception $e) {
            session()->flash('cf-error', 'Failed to delete firewall rule: ' . $e->getMessage());
        }
    }

    // IP Blocking via Cloudflare

    public function blockIpViaCf(): void
    {
        $this->validate([
            'blockIp' => 'required|ip',
            'blockNote' => 'nullable|string|max:255',
        ]);

        $cf = $this->siteCloudflare;
        if (!$cf) return;

        $service = new CloudflareService($cf->cloudflareConnection);

        try {
            $service->blockIpViaCloudflare($cf->zone_id, $this->blockIp, $this->blockNote);
            $this->reset('blockIp', 'blockNote');
            Cache::forget($this->accessRulesCacheKey());
            unset($this->accessRules);
            session()->flash('cf-success', 'IP address blocked via Cloudflare.');
        } catch (\Exception $e) {
            session()->flash('cf-error', 'Failed to block IP: ' . $e->getMessage());
        }
    }

    public function removeAccessRule(string $ruleId): void
    {
        $cf = $this->siteCloudflare;
        if (!$cf) return;

        $service = new CloudflareService($cf->cloudflareConnection);

        try {
            $service->deleteAccessRule($cf->zone_id, $ruleId);
            Cache::forget($this->accessRulesCacheKey());
            unset($this->accessRules);
            session()->flash('cf-success', 'Access rule removed.');
        } catch (\Exception $e) {
            session()->flash('cf-error', 'Failed to remove access rule: ' . $e->getMessage());
        }
    }

    // Analytics

    #[Computed]
    public function analytics(): array
    {
        $cf = $this->siteCloudflare;
        if (!$cf) return [];

        try {
            $service = new CloudflareService($cf->cloudflareConnection);
            return $service->getAnalytics($cf->zone_id, $this->analyticsPeriod);
        } catch (\Exception $e) {
            return [];
        }
    }

    public function render()
    {
        return view('livewire.sites.detail.site-cloudflare')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name . ' — Cloudflare',
            ]);
    }
}
