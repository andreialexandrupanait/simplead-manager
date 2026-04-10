<?php

declare(strict_types=1);

namespace App\Services\SeoChecks;

class SeoPluginCheck
{
    public function check(array $connectorData, ?array $gscData = null): array
    {
        $plugin = $connectorData['seo_plugin'] ?? null;

        if (! $plugin) {
            return [[
                'category' => 'plugin',
                'severity' => 'high',
                'title' => 'No SEO plugin detected',
                'description' => 'No recognized SEO plugin is installed on this site.',
                'url' => null,
                'recommendation' => 'Install a dedicated SEO plugin such as Yoast SEO, Rank Math, or All in One SEO to manage meta tags, sitemaps, and structured data.',
                'meta' => null,
            ]];
        }

        if (! ($plugin['active'] ?? false)) {
            return [[
                'category' => 'plugin',
                'severity' => 'high',
                'title' => "SEO plugin \"{$plugin['name']}\" is inactive",
                'description' => "The SEO plugin \"{$plugin['name']}\" is installed but not active.",
                'url' => null,
                'recommendation' => "Activate the \"{$plugin['name']}\" plugin to ensure SEO features are running.",
                'meta' => [
                    'plugin_name' => $plugin['name'] ?? null,
                    'plugin_file' => $plugin['file'] ?? null,
                    'plugin_version' => $plugin['version'] ?? null,
                ],
            ]];
        }

        return [[
            'category' => 'plugin',
            'severity' => 'info',
            'title' => "SEO plugin \"{$plugin['name']}\" is active",
            'description' => "Version {$plugin['version']} is installed and active.",
            'url' => null,
            'recommendation' => null,
            'meta' => [
                'plugin_name' => $plugin['name'] ?? null,
                'plugin_file' => $plugin['file'] ?? null,
                'plugin_version' => $plugin['version'] ?? null,
            ],
        ]];
    }
}
