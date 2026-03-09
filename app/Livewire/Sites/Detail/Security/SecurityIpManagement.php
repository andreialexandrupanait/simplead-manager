<?php

namespace App\Livewire\Sites\Detail\Security;

use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\SecurityBannedIp;
use App\Models\SecurityIpList;
use App\Models\SecuritySetting;
use App\Models\Site;
use App\Services\SecuritySettingsService;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SecurityIpManagement extends Component
{
    use WithSiteAuthorization;

    public Site $site;
    public string $subTab = 'whitelist';

    // Add IP form
    public string $newIp = '';
    public string $newReason = '';
    public ?string $newExpiresAt = null;

    // Firewall settings
    public bool $firewallEnabled = false;
    public string $ipHeaderOverride = '';
    public bool $roleWhitelist = false;

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
        $this->loadFirewallSettings();
    }

    protected function loadFirewallSettings(): void
    {
        $setting = SecuritySetting::where('site_id', $this->site->id)
            ->where('category', 'ip_management')
            ->where('setting_key', 'firewall_config')
            ->first();

        if ($setting) {
            $val = $setting->setting_value ?? [];
            $this->firewallEnabled = $val['enabled'] ?? false;
            $this->ipHeaderOverride = $val['ip_header_override'] ?? '';
            $this->roleWhitelist = $val['role_whitelist'] ?? false;
        }
    }

    #[Computed]
    public function whitelist()
    {
        return SecurityIpList::forSite($this->site->id)
            ->whitelist()
            ->active()
            ->orderByDesc('created_at')
            ->get();
    }

    #[Computed]
    public function blocklist()
    {
        return SecurityIpList::forSite($this->site->id)
            ->blocklist()
            ->active()
            ->orderByDesc('created_at')
            ->get();
    }

    #[Computed]
    public function bannedIps()
    {
        return SecurityBannedIp::forSite($this->site->id)
            ->active()
            ->orderByDesc('banned_at')
            ->get();
    }

    public function addIp(): void
    {
        $this->validate([
            'newIp' => ['required', 'string', 'max:49', function ($attribute, $value, $fail) {
                if (filter_var($value, FILTER_VALIDATE_IP)) {
                    return;
                }

                if (preg_match('/^(.+)\/(\d+)$/', $value, $matches)) {
                    $ip = $matches[1];
                    $prefix = (int) $matches[2];

                    if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                        $fail('Invalid IP address in CIDR notation.');
                        return;
                    }

                    $maxPrefix = str_contains($ip, ':') ? 128 : 32;
                    if ($prefix < 0 || $prefix > $maxPrefix) {
                        $fail("CIDR prefix must be between 0 and {$maxPrefix}.");
                        return;
                    }

                    return;
                }

                $fail('Must be a valid IP address or CIDR notation (e.g. 192.168.1.0/24).');
            }],
            'newReason' => 'nullable|string|max:500',
            'newExpiresAt' => 'nullable|date|after:now',
        ]);

        SecurityIpList::create([
            'site_id' => $this->site->id,
            'ip_address' => $this->newIp,
            'list_type' => $this->subTab === 'blocklist' ? 'blocklist' : 'whitelist',
            'reason' => $this->newReason ?: null,
            'expires_at' => $this->newExpiresAt ?: null,
        ]);

        $this->reset('newIp', 'newReason', 'newExpiresAt');
        unset($this->whitelist, $this->blocklist);
        session()->flash('ip-success', 'IP address added.');
        $this->redirect(route('sites.security.ip-management', $this->site), navigate: false);
    }

    public function removeIp(int $id): void
    {
        SecurityIpList::where('id', $id)
            ->where('site_id', $this->site->id)
            ->delete();

        unset($this->whitelist, $this->blocklist);
    }

    public function unbanIp(int $id): void
    {
        SecurityBannedIp::where('id', $id)
            ->where('site_id', $this->site->id)
            ->delete();

        unset($this->bannedIps);
    }

    public function saveFirewallSettings(): void
    {
        $this->validate([
            'ipHeaderOverride' => 'nullable|string|max:100',
        ]);

        app(SecuritySettingsService::class)->applySetting(
            $this->site,
            'ip_management',
            'firewall_config',
            [
                'enabled' => $this->firewallEnabled,
                'ip_header_override' => $this->ipHeaderOverride,
                'role_whitelist' => $this->roleWhitelist,
            ],
            $this->firewallEnabled,
        );

        session()->flash('ip-success', 'Firewall settings saved.');
        $this->redirect(route('sites.security.ip-management', $this->site), navigate: false);
    }

    public function render()
    {
        return view('livewire.sites.detail.security.security-ip-management')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name . ' — IP Management',
            ]);
    }
}
