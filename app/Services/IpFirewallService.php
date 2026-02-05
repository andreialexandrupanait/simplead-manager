<?php

namespace App\Services;

use App\Models\BlockedRequest;
use App\Models\IpRule;
use App\Models\Site;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class IpFirewallService
{
    public static function addRule(Site $site, string $ip, string $type, ?string $reason = null, ?Carbon $expiresAt = null): IpRule
    {
        $rule = IpRule::create([
            'site_id' => $site->id,
            'ip_address' => $ip,
            'type' => $type,
            'reason' => $reason,
            'expires_at' => $expiresAt,
            'created_by' => auth()->id(),
            'is_synced' => false,
        ]);

        static::syncToSite($site);

        ActivityLogger::log(
            'security',
            'info',
            "IP {$type} rule added: {$ip}",
            $reason,
            $site,
            ['ip' => $ip, 'type' => $type],
            'shield'
        );

        return $rule;
    }

    public static function removeRule(IpRule $rule): void
    {
        $site = $rule->site;
        $ip = $rule->ip_address;
        $type = $rule->type;

        $rule->delete();

        if ($site) {
            static::syncToSite($site);

            ActivityLogger::log(
                'security',
                'info',
                "IP {$type} rule removed: {$ip}",
                null,
                $site,
                ['ip' => $ip, 'type' => $type],
                'shield'
            );
        }
    }

    public static function syncToSite(Site $site): void
    {
        try {
            $rules = IpRule::forSite($site->id)->active()->get();

            $payload = $rules->map(fn (IpRule $rule) => [
                'ip' => $rule->ip_address,
                'type' => $rule->type,
                'reason' => $rule->reason,
                'expires_at' => $rule->expires_at?->toIso8601String(),
            ])->toArray();

            $api = new WordPressApiService($site);
            $api->syncIpRules($payload);

            IpRule::forSite($site->id)->update(['is_synced' => true]);
        } catch (\Exception $e) {
            Log::warning("IP rules sync failed for site {$site->id}: {$e->getMessage()}");
        }
    }

    public static function fetchBlockedRequests(Site $site): int
    {
        try {
            $api = new WordPressApiService($site);

            $lastRequest = BlockedRequest::where('site_id', $site->id)
                ->orderByDesc('blocked_at')
                ->first();

            $since = $lastRequest?->blocked_at?->toIso8601String();
            $result = $api->getBlockedRequests($since);

            $requests = $result['requests'] ?? [];
            $count = 0;

            foreach ($requests as $req) {
                $blockedAt = isset($req['blocked_at']) ? Carbon::parse($req['blocked_at']) : now();

                // Find matching rule
                $ruleId = null;
                if (isset($req['ip_address'])) {
                    $rule = IpRule::forSite($site->id)
                        ->where('ip_address', $req['ip_address'])
                        ->first();
                    $ruleId = $rule?->id;

                    if ($rule) {
                        $rule->increment('hits_count');
                        $rule->update(['last_hit_at' => $blockedAt]);
                    }
                }

                BlockedRequest::create([
                    'site_id' => $site->id,
                    'ip_rule_id' => $ruleId,
                    'ip_address' => $req['ip_address'] ?? 'unknown',
                    'request_url' => $req['request_url'] ?? null,
                    'user_agent' => $req['user_agent'] ?? null,
                    'blocked_at' => $blockedAt,
                ]);
                $count++;
            }

            return $count;
        } catch (\Exception $e) {
            Log::warning("Fetch blocked requests failed for site {$site->id}: {$e->getMessage()}");
            return 0;
        }
    }
}
