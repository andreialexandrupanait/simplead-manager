<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SitePlugin;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PluginRiskAssessmentService
{
    /**
     * Assess the risk of updating a plugin using AI analysis.
     *
     * @return array{score: int, level: string, reasons: array, recommendation: string}
     */
    public function assess(SitePlugin $plugin): array
    {
        $changelog = $this->fetchChangelog($plugin->slug);
        $pluginInfo = $this->fetchPluginInfo($plugin->slug);

        $context = $this->buildContext($plugin, $changelog, $pluginInfo);

        $assessment = $this->callClaude($context);

        if (! $assessment) {
            return [
                'score' => 50,
                'level' => 'unknown',
                'reasons' => ['AI assessment unavailable'],
                'recommendation' => 'Proceed with caution — AI analysis could not be performed.',
            ];
        }

        return $assessment;
    }

    private function buildContext(SitePlugin $plugin, ?string $changelog, ?array $pluginInfo): string
    {
        $parts = [
            "Plugin: {$plugin->name} ({$plugin->slug})",
            "Current version: {$plugin->version}",
            "Update version: {$plugin->update_version}",
            'Active: '.($plugin->is_active ? 'Yes' : 'No'),
            'Abandoned: '.($plugin->is_abandoned ? 'Yes' : 'No'),
            'Closed on WP.org: '.($plugin->is_closed ? "Yes ({$plugin->closed_reason})" : 'No'),
        ];

        if ($pluginInfo) {
            $parts[] = 'Active installs: '.($pluginInfo['active_installs'] ?? 'unknown');
            $parts[] = 'Tested up to WP: '.($pluginInfo['tested'] ?? 'unknown');
            $parts[] = 'Requires PHP: '.($pluginInfo['requires_php'] ?? 'unknown');
            $parts[] = 'Last updated: '.($pluginInfo['last_updated'] ?? 'unknown');
            $parts[] = 'Rating: '.($pluginInfo['rating'] ?? 'unknown').'/100';
        }

        if ($changelog) {
            $trimmed = mb_substr(strip_tags($changelog), 0, 3000);
            $parts[] = "\nChangelog (latest entries):\n{$trimmed}";
        } else {
            $parts[] = "\nChangelog: Not available on WordPress.org";
        }

        return implode("\n", $parts);
    }

    /**
     * The Anthropic key, from the Integrations settings screen if it was entered
     * there, otherwise from the environment.
     *
     * This used to be assembled by IncidentResponseConfigServiceProvider, which
     * survived the deletion of the incident-response module purely to seed
     * config('incident-response.ai.api_key') for this one service — a boot-time
     * provider and a whole config file named after a feature that no longer
     * exists. Reading the setting here is the same lookup, in the open.
     */
    private function apiKey(): ?string
    {
        $stored = app(SettingsService::class)->get('ai_anthropic_api_key');

        if (is_string($stored) && $stored !== '') {
            try {
                return decrypt($stored);
            } catch (\Throwable) {
                // A key we cannot decrypt is a key we do not have; fall through.
            }
        }

        $fromEnv = config('updates.ai.api_key');

        return is_string($fromEnv) && $fromEnv !== '' ? $fromEnv : null;
    }

    private function callClaude(string $context): ?array
    {
        $apiKey = $this->apiKey();
        if (! $apiKey) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
                'model' => config('updates.ai.model', 'claude-sonnet-4-5-20250929'),
                'max_tokens' => 1024,
                'temperature' => 0.1,
                'system' => 'You are a WordPress plugin update risk analyst. Analyze the plugin update context and return a JSON object with exactly these fields: score (0-100 where 0=safe, 100=very risky), level ("safe" if score<30, "caution" if 30-70, "risky" if >70), reasons (array of 2-4 short reason strings), recommendation (one sentence). Consider: breaking changes in changelog, plugin popularity, maintenance activity, PHP/WP compatibility, whether the update is a major version bump. Return ONLY valid JSON, no markdown.',
                'messages' => [
                    ['role' => 'user', 'content' => $context],
                ],
            ]);

            if (! $response->successful()) {
                Log::warning('Plugin risk assessment: Claude API error', ['status' => $response->status()]);

                return null;
            }

            $data = $response->json();
            $text = $data['content'][0]['text'] ?? '';

            $json = json_decode($text, true);
            if (! $json || ! isset($json['score'])) {
                preg_match('/\{[^}]+\}/s', $text, $matches);
                if ($matches) {
                    $json = json_decode($matches[0], true);
                }
            }

            if ($json && isset($json['score'])) {
                return [
                    'score' => (int) max(0, min(100, $json['score'])),
                    'level' => $json['level'] ?? ($json['score'] < 30 ? 'safe' : ($json['score'] > 70 ? 'risky' : 'caution')),
                    'reasons' => (array) ($json['reasons'] ?? []),
                    'recommendation' => (string) ($json['recommendation'] ?? ''),
                ];
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning("Plugin risk assessment failed: {$e->getMessage()}");

            return null;
        }
    }

    private function fetchChangelog(string $slug): ?string
    {
        return Cache::remember("wp_org_changelog:plugin:{$slug}", 3600, function () use ($slug) {
            try {
                $response = Http::timeout(10)->get('https://api.wordpress.org/plugins/info/1.2/', [
                    'action' => 'plugin_information',
                    'request[slug]' => $slug,
                    'request[fields][sections]' => true,
                ]);

                return $response->successful() ? ($response->json()['sections']['changelog'] ?? null) : null;
            } catch (\Throwable) {
                return null;
            }
        });
    }

    private function fetchPluginInfo(string $slug): ?array
    {
        return Cache::remember("wp_org_info:{$slug}", 3600, function () use ($slug) {
            try {
                $response = Http::timeout(10)->get('https://api.wordpress.org/plugins/info/1.2/', [
                    'action' => 'plugin_information',
                    'request[slug]' => $slug,
                ]);

                return $response->successful() ? $response->json() : null;
            } catch (\Throwable) {
                return null;
            }
        });
    }
}
