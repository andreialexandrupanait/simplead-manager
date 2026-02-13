<?php

namespace App\Livewire\Sites\Detail;

use App\Jobs\FetchBlockedRequests;
use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\BlockedRequest;
use App\Models\IpRule;
use App\Models\Site;
use App\Services\IpFirewallService;
use Carbon\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SiteFirewall extends Component
{
    use WithSiteAuthorization;

    public Site $site;
    public string $tab = 'block';

    // Form state
    public string $newIp = '';
    public string $newReason = '';
    public ?string $newExpiry = null;

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
    }

    #[Computed]
    public function blockRules()
    {
        return IpRule::forSite($this->site->id)
            ->blocked()
            ->active()
            ->orderByDesc('created_at')
            ->get();
    }

    #[Computed]
    public function allowRules()
    {
        return IpRule::forSite($this->site->id)
            ->allowed()
            ->active()
            ->orderByDesc('created_at')
            ->get();
    }

    #[Computed]
    public function blockedRequests()
    {
        return BlockedRequest::where('site_id', $this->site->id)
            ->orderByDesc('blocked_at')
            ->limit(100)
            ->get();
    }

    #[Computed]
    public function stats()
    {
        return [
            'total_rules' => IpRule::forSite($this->site->id)->active()->count(),
            'total_blocked' => BlockedRequest::where('site_id', $this->site->id)->count(),
            'blocked_today' => BlockedRequest::where('site_id', $this->site->id)
                ->where('blocked_at', '>=', now()->startOfDay())
                ->count(),
        ];
    }

    public function addRule(): void
    {
        $this->validate([
            'newIp' => ['required', 'string', function ($attribute, $value, $fail) {
                // Validate IPv4, IPv6, or CIDR notation
                if (!filter_var($value, FILTER_VALIDATE_IP) && !preg_match('/^[\d\.]+\/\d{1,2}$/', $value) && !preg_match('/^[a-f\d:]+\/\d{1,3}$/i', $value)) {
                    $fail('Please enter a valid IP address or CIDR range.');
                }
            }],
            'newReason' => 'nullable|string|max:255',
            'newExpiry' => 'nullable|date|after:now',
        ]);

        $expiresAt = $this->newExpiry ? Carbon::parse($this->newExpiry) : null;

        IpFirewallService::addRule(
            $this->site,
            $this->newIp,
            $this->tab,
            $this->newReason ?: null,
            $expiresAt
        );

        $this->reset('newIp', 'newReason', 'newExpiry');
        unset($this->blockRules, $this->allowRules, $this->stats);
    }

    public function removeRule(int $id): void
    {
        $rule = IpRule::find($id);
        if ($rule && ($rule->site_id === $this->site->id || $rule->site_id === null)) {
            IpFirewallService::removeRule($rule);
        }
        unset($this->blockRules, $this->allowRules, $this->stats);
    }

    public function fetchBlocked(): void
    {
        FetchBlockedRequests::dispatch($this->site);
        session()->flash('fetch-dispatched', 'Fetching latest blocked requests.');
        unset($this->blockedRequests, $this->stats);
    }

    public function render()
    {
        return view('livewire.sites.detail.site-firewall')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name . ' — Firewall',
            ]);
    }
}
